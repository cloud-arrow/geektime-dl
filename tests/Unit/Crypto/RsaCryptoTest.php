<?php

declare(strict_types=1);

use App\Crypto\RsaCrypto;

test('encrypt returns base64 encoded string', function () {
    $result = RsaCrypto::encrypt('test data');

    // Result should be valid base64
    expect(base64_decode($result, true))->not->toBeFalse();
});

test('encrypt output length matches RSA key size', function () {
    $result = RsaCrypto::encrypt('test');
    $raw = base64_decode($result, true);

    // RSA-512 key produces 64-byte ciphertext
    expect(strlen($raw))->toBe(64);
});

test('encrypt produces different output for same input due to PKCS1 random padding', function () {
    $result1 = RsaCrypto::encrypt('same input');
    $result2 = RsaCrypto::encrypt('same input');

    // PKCS1v15 uses random padding, so outputs should differ
    expect($result1)->not->toBe($result2);
});

test('encrypt handles empty string', function () {
    $result = RsaCrypto::encrypt('');
    expect(base64_decode($result, true))->not->toBeFalse();
    expect(strlen(base64_decode($result, true)))->toBe(64);
});

test('encrypt handles short input', function () {
    $result = RsaCrypto::encrypt('a');
    expect(base64_decode($result, true))->not->toBeFalse();
});
