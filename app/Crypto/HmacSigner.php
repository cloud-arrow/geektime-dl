<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * HMAC-SHA1 signing ported from Go internal/pkg/crypto/hmac.go.
 *
 * Note: The Go code appends an ampersand '&' to the access key secret
 * before using it as the HMAC key. This is part of Aliyun's API signing spec.
 */
final class HmacSigner
{
    /**
     * Generate HMAC-SHA1 signature, base64-encoded.
     *
     * Matches Go's HmacSHA1Signature:
     *   key = accessKeySecret + "&"
     *   mac = hmac.New(sha1.New, key)
     *   mac.Write(stringToSign)
     *   return base64(mac.Sum(nil))
     *
     * @param  string  $accessKeySecret  The Aliyun access key secret
     * @param  string  $stringToSign  The canonical string to sign
     * @return string Base64-encoded HMAC-SHA1 signature
     */
    public static function sign(string $accessKeySecret, string $stringToSign): string
    {
        // Append '&' to access key secret per Aliyun signing spec
        $key = $accessKeySecret.'&';

        $hmac = hash_hmac('sha1', $stringToSign, $key, binary: true);

        return base64_encode($hmac);
    }
}
