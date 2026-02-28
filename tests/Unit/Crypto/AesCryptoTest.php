<?php

declare(strict_types=1);

use App\Crypto\AesCrypto;

test('decryptCbc roundtrip with 16-byte key', function () {
    $key = '1234567890123456';
    $iv = 'abcdefghijklmnop';
    $plaintext = 'Hello, World!!!';

    // Encrypt with standard OpenSSL (PKCS7 padding is default with OPENSSL_RAW_DATA)
    $encrypted = openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

    $decrypted = AesCrypto::decryptCbc($encrypted, $key, $iv);
    expect($decrypted)->toBe($plaintext);
});

test('decryptCbc roundtrip with 24-byte key', function () {
    $key = '123456789012345678901234'; // 24 bytes
    $iv = 'abcdefghijklmnop';
    $plaintext = 'AES-192 test data here!';

    $encrypted = openssl_encrypt($plaintext, 'aes-192-cbc', $key, OPENSSL_RAW_DATA, $iv);

    $decrypted = AesCrypto::decryptCbc($encrypted, $key, $iv);
    expect($decrypted)->toBe($plaintext);
});

test('decryptCbc roundtrip with 32-byte key', function () {
    $key = '12345678901234567890123456789012'; // 32 bytes
    $iv = 'abcdefghijklmnop';
    $plaintext = 'AES-256 test!';

    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    $decrypted = AesCrypto::decryptCbc($encrypted, $key, $iv);
    expect($decrypted)->toBe($plaintext);
});

test('decryptEcb roundtrip with block-aligned data', function () {
    $key = '1234567890123456';
    $plaintext = str_repeat('A', 32); // 2 blocks

    // Encrypt with ECB, zero padding (no auto-padding since data is block-aligned)
    $encrypted = openssl_encrypt($plaintext, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

    $decrypted = AesCrypto::decryptEcb($encrypted, $key);
    expect($decrypted)->toBe($plaintext);
});

test('decryptEcb with single block', function () {
    $key = '1234567890123456';
    $plaintext = '0123456789abcdef'; // exactly 16 bytes

    $encrypted = openssl_encrypt($plaintext, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

    $decrypted = AesCrypto::decryptEcb($encrypted, $key);
    expect($decrypted)->toBe($plaintext);
});

test('decryptEcb returns empty for empty input', function () {
    $key = '1234567890123456';
    $result = AesCrypto::decryptEcb('', $key);
    expect($result)->toBe('');
});

test('decryptCbc handles exact block-size plaintext', function () {
    $key = '1234567890123456';
    $iv = 'abcdefghijklmnop';
    $plaintext = '0123456789abcdef'; // exactly 16 bytes

    $encrypted = openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

    $decrypted = AesCrypto::decryptCbc($encrypted, $key, $iv);
    expect($decrypted)->toBe($plaintext);
});
