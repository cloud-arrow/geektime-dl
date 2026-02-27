<?php

declare(strict_types=1);

namespace App\Crypto;

use RuntimeException;

/**
 * AES cryptographic operations ported from Go internal/pkg/crypto/aes.go.
 *
 * Provides:
 * - GetAESDecryptKey: Aliyun private encryption key derivation
 * - AES-CBC decryption with PKCS7 unpadding
 * - AES-ECB decryption (no padding, manual block-by-block)
 */
final class AesCrypto
{
    /**
     * Derive the AES decrypt key using Aliyun's private encryption method.
     *
     * Algorithm (from Go source comments):
     *   r0 = clientRand (e.g. "cmMeyfzJWyZcSwyH")
     *   r1 = md5(r0)
     *   t1 = r1.substring(8, 24)
     *   iv = t1 bytes
     *   dc1 = aes_cbc_decrypt(base64_decode(serverRand), iv, iv)
     *   r2 = r0 + dc1
     *   r2md5 = md5(r2)
     *   t2 = r2md5.substring(8, 24)
     *   key2 = t2 bytes
     *   d2c = aes_cbc_decrypt(base64_decode(plainText), key2, iv)
     *   result = hex(base64_decode(d2c))
     *
     * @param  string  $clientRand  Client random string (UUID)
     * @param  string  $serverRand  Server response random (base64-encoded encrypted data)
     * @param  string  $plainText  Server response plain text (base64-encoded encrypted data)
     * @return string Hex-encoded decryption key
     */
    public static function getAesDecryptKey(string $clientRand, string $serverRand, string $plainText): string
    {
        // Step 1: MD5 of client random, take chars [8..24) as IV
        $crMd5 = md5($clientRand); // hex string, 32 chars
        $t1 = substr($crMd5, 8, 16);
        $iv = $t1; // 16 bytes (ASCII chars of hex substring)

        // Step 2: Decrypt server random with IV as both key and IV
        $sd = base64_decode($serverRand, true);
        if ($sd === false) {
            throw new RuntimeException('Failed to base64 decode server random');
        }
        $dc1 = self::decryptCbc($sd, $iv, $iv);

        // Step 3: Concatenate client random + decrypted result, MD5 it, take chars [8..24) as key2
        $r2 = $clientRand.$dc1;
        $r2Md5 = md5($r2);
        $t2 = substr($r2Md5, 8, 16);
        $key2 = $t2;

        // Step 4: Decrypt plain text with key2 and original IV
        $pd = base64_decode($plainText, true);
        if ($pd === false) {
            throw new RuntimeException('Failed to base64 decode plain text');
        }
        $d2c = self::decryptCbc($pd, $key2, $iv);

        // Step 5: Base64 decode the decrypted result, then hex encode
        $b = base64_decode($d2c, true);
        if ($b === false) {
            throw new RuntimeException('Failed to base64 decode final decrypted data');
        }

        return bin2hex($b);
    }

    /**
     * AES-CBC decrypt with PKCS7 unpadding.
     *
     * @param  string  $encrypted  Raw encrypted bytes
     * @param  string  $key  AES key (16, 24, or 32 bytes)
     * @param  string  $iv  Initialization vector (16 bytes)
     * @return string Decrypted plaintext with PKCS7 padding removed
     */
    public static function decryptCbc(string $encrypted, string $key, string $iv): string
    {
        // Use raw data mode (OPENSSL_RAW_DATA) and zero padding,
        // then handle PKCS7 unpadding manually to match Go exactly
        $decrypted = openssl_decrypt(
            $encrypted,
            self::getCbcCipher($key),
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($iv, 0, 16)
        );

        if ($decrypted === false) {
            throw new RuntimeException('AES-CBC decryption failed: '.openssl_error_string());
        }

        return self::pkcs7Unpad($decrypted);
    }

    /**
     * AES-ECB decrypt without padding (manual block-by-block).
     *
     * Matches Go's AESDecryptECB which processes 16-byte blocks
     * without any padding removal.
     *
     * @param  string  $encrypted  Raw encrypted bytes (must be multiple of 16)
     * @param  string  $key  AES key (16, 24, or 32 bytes)
     * @return string Decrypted data (same length as input)
     */
    public static function decryptEcb(string $encrypted, string $key): string
    {
        $blockSize = 16;
        $length = strlen($encrypted);
        $decrypted = '';

        // Process block by block to match Go's manual ECB implementation exactly
        for ($offset = 0; $offset < $length; $offset += $blockSize) {
            $block = substr($encrypted, $offset, $blockSize);
            $decryptedBlock = openssl_decrypt(
                $block,
                self::getEcbCipher($key),
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            );

            if ($decryptedBlock === false) {
                throw new RuntimeException('AES-ECB decryption failed at offset '.$offset.': '.openssl_error_string());
            }

            $decrypted .= $decryptedBlock;
        }

        return $decrypted;
    }

    /**
     * Remove PKCS7 padding from decrypted data.
     *
     * Matches Go's pkcs5UnPadding (PKCS5 is a subset of PKCS7 for 16-byte blocks).
     */
    private static function pkcs7Unpad(string $data): string
    {
        $length = strlen($data);
        if ($length === 0) {
            return $data;
        }

        $padding = ord($data[$length - 1]);

        if ($padding < 1 || $padding > 16 || $padding > $length) {
            return $data;
        }

        return substr($data, 0, $length - $padding);
    }

    /**
     * Determine the OpenSSL CBC cipher name based on key length.
     */
    private static function getCbcCipher(string $key): string
    {
        return match (strlen($key)) {
            16 => 'aes-128-cbc',
            24 => 'aes-192-cbc',
            32 => 'aes-256-cbc',
            default => 'aes-128-cbc',
        };
    }

    /**
     * Determine the OpenSSL ECB cipher name based on key length.
     */
    private static function getEcbCipher(string $key): string
    {
        return match (strlen($key)) {
            16 => 'aes-128-ecb',
            24 => 'aes-192-ecb',
            32 => 'aes-256-ecb',
            default => 'aes-128-ecb',
        };
    }
}
