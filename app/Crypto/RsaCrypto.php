<?php

declare(strict_types=1);

namespace App\Crypto;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use RuntimeException;

/**
 * RSA-1024 PKCS1v15 encryption ported from Go internal/pkg/crypto/rsa.go.
 *
 * Uses phpseclib3 because OpenSSL 3.x rejects 1024-bit RSA keys by default.
 * The public key is hardcoded from the Go source.
 */
final class RsaCrypto
{
    /**
     * Hardcoded RSA-1024 public key from Go source.
     */
    private const PUBLIC_KEY_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBAIcLeIt2wmIyXckgNhCGpMTAZyBGO+nk0/IdOrhIdfRR
gBLHdydsftMVPNHrRuPKQNZRslWE1vvgx80w9lCllIUCAwEAAQ==
-----END PUBLIC KEY-----
PEM;

    private static ?PublicKey $publicKey = null;

    /**
     * Encrypt data using RSA PKCS1v15 and return base64-encoded ciphertext.
     *
     * Matches Go's RSAEncrypt: rsa.EncryptPKCS1v15(rand.Reader, pub, origData)
     * then base64.StdEncoding.EncodeToString(data).
     *
     * @param  string  $plaintext  Raw plaintext bytes to encrypt
     * @return string Base64-encoded ciphertext
     *
     * @throws RuntimeException If encryption fails
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getPublicKey();

        $ciphertext = $key->encrypt($plaintext);

        if ($ciphertext === false) {
            throw new RuntimeException('RSA encryption failed');
        }

        return base64_encode($ciphertext);
    }

    /**
     * Load and cache the RSA public key using phpseclib3.
     *
     * Configures PKCS1v15 padding to match Go's rsa.EncryptPKCS1v15.
     */
    private static function getPublicKey(): PublicKey
    {
        if (self::$publicKey === null) {
            $loaded = PublicKeyLoader::load(self::PUBLIC_KEY_PEM);

            if (! $loaded instanceof PublicKey) {
                throw new RuntimeException('Failed to load RSA public key');
            }

            // Use PKCS1v15 padding (ENCRYPTION_PKCS1) to match Go's rsa.EncryptPKCS1v15
            /** @var PublicKey $key */
            $key = $loaded->withPadding(RSA::ENCRYPTION_PKCS1);

            self::$publicKey = $key;
        }

        return self::$publicKey;
    }
}
