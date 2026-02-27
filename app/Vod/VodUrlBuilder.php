<?php

declare(strict_types=1);

namespace App\Vod;

use App\Crypto\HmacSigner;
use App\Crypto\RsaCrypto;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds Aliyun VOD API URLs for GetPlayInfo requests.
 *
 * Ported from Go internal/video/vod/vod.go.
 *
 * The process:
 * 1. Decode playAuth (base64-encoded JSON, possibly "signed")
 * 2. Encrypt client random with RSA
 * 3. Build API parameters with action=GetPlayInfo
 * 4. Sign with HMAC-SHA1 per Aliyun API spec
 */
final class VodUrlBuilder
{
    private const VOD_ENDPOINT = 'https://vod.cn-shanghai.aliyuncs.com/?';

    /**
     * Signature markers embedded in "signed" playAuth tokens.
     * Each int is offset by its array index: byte = value - index
     */
    private const PLAY_AUTH_SIGN1 = [52, 58, 53, 121, 116, 102];

    private const PLAY_AUTH_SIGN2 = [90, 91];

    /**
     * Build the full VOD GetPlayInfo URL with signed parameters.
     *
     * @param  string  $playAuth  Base64-encoded (possibly signed) playAuth token
     * @param  string  $videoId  Aliyun video ID
     * @param  string  $clientRand  Client-generated random string (UUID)
     * @return string Complete signed API URL
     *
     * @throws RuntimeException If playAuth decoding or RSA encryption fails
     */
    public static function buildUrl(string $playAuth, string $videoId, string $clientRand): string
    {
        $decodedPlayAuth = self::decodePlayAuth($playAuth);

        $playAuthData = json_decode($decodedPlayAuth, true);
        if (! is_array($playAuthData)) {
            throw new RuntimeException('Failed to decode playAuth JSON: '.$decodedPlayAuth);
        }

        $encryptedClientRand = RsaCrypto::encrypt($clientRand);

        $publicParams = [
            'AccessKeyId' => (string) ($playAuthData['AccessKeyId'] ?? ''),
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => Str::uuid()->toString(),
            'Format' => 'JSON',
            'Channel' => 'HTML5',
            'StreamType' => 'video',
            'Rand' => $encryptedClientRand,
            'Formats' => '',
            'Version' => '2017-03-21',
        ];

        $privateParams = [
            'Action' => 'GetPlayInfo',
            'AuthInfo' => (string) ($playAuthData['AuthInfo'] ?? ''),
            'AuthTimeout' => '7200',
            'PlayConfig' => '{}',
            'PlayerVersion' => '2.8.2',
            'ReAuthInfo' => '{}',
            'SecurityToken' => (string) ($playAuthData['SecurityToken'] ?? ''),
            'VideoId' => $videoId,
        ];

        $allParams = self::getAllParams($publicParams, $privateParams);
        $cqs = self::getCqs($allParams);
        $stringToSign = 'GET'.'&'.self::percentEncode('/').'&'.self::percentEncode($cqs);

        $accessKeySecret = (string) ($playAuthData['AccessKeySecret'] ?? '');
        $signature = HmacSigner::sign($accessKeySecret, $stringToSign);

        $queryString = $cqs.'&Signature='.self::percentEncode($signature);

        return self::VOD_ENDPOINT.$queryString;
    }

    /**
     * Decode playAuth, handling both plain and "signed" formats.
     *
     * Signed playAuth has marker characters inserted and each byte incremented.
     */
    private static function decodePlayAuth(string $playAuth): string
    {
        if (self::isSignedPlayAuth($playAuth)) {
            $playAuth = self::decodeSignedPlayAuth($playAuth);
        }

        $decoded = base64_decode($playAuth, true);
        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }

    /**
     * Detect if a playAuth token is "signed" by checking for marker strings.
     *
     * The Go code checks:
     *   signPos1 = time.Now().Year() / 100  (e.g. 2026/100 = 20)
     *   signPos2 = len(playAuth) - 2
     *   Check if sign1 appears at signPos1 and sign2 appears at signPos2
     */
    private static function isSignedPlayAuth(string $playAuth): bool
    {
        $signPos1 = intdiv((int) date('Y'), 100); // e.g. 2026 / 100 = 20
        $signPos2 = strlen($playAuth) - 2;

        $sign1 = self::getSignStr(self::PLAY_AUTH_SIGN1);
        $sign2 = self::getSignStr(self::PLAY_AUTH_SIGN2);

        if ($signPos1 + strlen($sign1) > strlen($playAuth)) {
            return false;
        }

        $r1 = substr($playAuth, $signPos1, strlen($sign1));
        $r2 = substr($playAuth, $signPos2);

        return $sign1 === $r1 && $r2 === $sign2;
    }

    /**
     * Decode a signed playAuth back to standard base64.
     *
     * 1. Remove the sign1 marker string (first occurrence)
     * 2. Remove the sign2 marker from the end
     * 3. Decrement each byte by 1, unless byte/factor == factor/10
     */
    private static function decodeSignedPlayAuth(string $playAuth): string
    {
        $sign1 = self::getSignStr(self::PLAY_AUTH_SIGN1);
        $sign2 = self::getSignStr(self::PLAY_AUTH_SIGN2);

        // Remove sign1 (first occurrence only)
        $pos = strpos($playAuth, $sign1);
        if ($pos !== false) {
            $playAuth = substr($playAuth, 0, $pos).substr($playAuth, $pos + strlen($sign1));
        }

        // Remove sign2 from end
        $playAuth = substr($playAuth, 0, strlen($playAuth) - strlen($sign2));

        // Decrement each character code by 1, unless code/factor == factor/10
        $factor = intdiv((int) date('Y'), 100); // e.g. 20
        $z = intdiv($factor, 10); // e.g. 2
        $chars = str_split($playAuth);

        foreach ($chars as $i => $char) {
            $code = ord($char);
            $r = intdiv($code, $factor);
            if ($r === $z) {
                // Keep character unchanged
                $chars[$i] = $char;
            } else {
                $chars[$i] = chr($code - 1);
            }
        }

        return implode('', $chars);
    }

    /**
     * Build the signature marker string from an array of ints.
     *
     * Each byte is computed as: value - index
     * Matches Go's getSignStr.
     *
     * @param  int[]  $sign  Array of integer values
     * @return string The decoded marker string
     */
    private static function getSignStr(array $sign): string
    {
        $result = '';
        foreach ($sign as $i => $b) {
            $result .= chr($b - $i);
        }

        return $result;
    }

    /**
     * URL-encode a string per Aliyun API spec.
     *
     * Matches Go's url.QueryEscape which is equivalent to PHP's rawurlencode
     * with the following adjustments to match Go behavior:
     * - Space is encoded as %20 (not +), which rawurlencode does by default
     * - Tilde ~ is not encoded, which rawurlencode handles correctly
     */
    private static function percentEncode(string $s): string
    {
        // Go's url.QueryEscape encodes space as '+', matching PHP's urlencode
        return urlencode($s);
    }

    /**
     * Build sorted canonical query string from all parameters.
     *
     * @param  string[]  $allParams  Array of "key=value" encoded parameter strings
     * @return string Sorted, ampersand-joined query string
     */
    private static function getCqs(array $allParams): string
    {
        sort($allParams, SORT_STRING);

        return implode('&', $allParams);
    }

    /**
     * Merge public and private params into a flat array of percent-encoded "key=value" strings.
     *
     * @param  array<string, string>  $publicParams
     * @param  array<string, string>  $privateParams
     * @return string[]
     */
    private static function getAllParams(array $publicParams, array $privateParams): array
    {
        $allParams = [];

        foreach ($publicParams as $key => $value) {
            $allParams[] = self::percentEncode($key).'='.self::percentEncode($value);
        }

        foreach ($privateParams as $key => $value) {
            $allParams[] = self::percentEncode($key).'='.self::percentEncode($value);
        }

        return $allParams;
    }
}
