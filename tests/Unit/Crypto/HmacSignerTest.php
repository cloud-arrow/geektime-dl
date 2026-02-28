<?php

declare(strict_types=1);

use App\Crypto\HmacSigner;

test('sign produces correct HMAC-SHA1 with known vector', function () {
    // Manual calculation: key = "secret&", data = "data"
    $expected = base64_encode(hash_hmac('sha1', 'data', 'secret&', true));

    expect(HmacSigner::sign('secret', 'data'))->toBe($expected);
});

test('sign appends ampersand to key', function () {
    // If key is "mykey", HMAC key should be "mykey&"
    $withAmpersand = base64_encode(hash_hmac('sha1', 'message', 'mykey&', true));
    $withoutAmpersand = base64_encode(hash_hmac('sha1', 'message', 'mykey', true));

    expect(HmacSigner::sign('mykey', 'message'))->toBe($withAmpersand);
    expect(HmacSigner::sign('mykey', 'message'))->not->toBe($withoutAmpersand);
});

test('sign handles empty strings', function () {
    $expected = base64_encode(hash_hmac('sha1', '', '&', true));
    expect(HmacSigner::sign('', ''))->toBe($expected);
});

test('sign returns base64 encoded result', function () {
    $result = HmacSigner::sign('key', 'data');
    expect(base64_decode($result, true))->not->toBeFalse();
    // SHA1 produces 20 bytes
    expect(strlen(base64_decode($result, true)))->toBe(20);
});
