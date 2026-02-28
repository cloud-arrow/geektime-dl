<?php

declare(strict_types=1);

use App\Vod\VodUrlBuilder;

test('getSignStr converts PLAY_AUTH_SIGN1 correctly', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'getSignStr');
    $method->setAccessible(true);

    $sign1 = [52, 58, 53, 121, 116, 102];
    $result = $method->invoke(null, $sign1);

    // chr(52-0)='4', chr(58-1)='9', chr(53-2)='3', chr(121-3)='v', chr(116-4)='p', chr(102-5)='a'
    expect($result)->toBe('493vpa');
});

test('getSignStr converts PLAY_AUTH_SIGN2 correctly', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'getSignStr');
    $method->setAccessible(true);

    $sign2 = [90, 91];
    $result = $method->invoke(null, $sign2);

    // chr(90-0)='Z', chr(91-1)='Z'
    expect($result)->toBe('ZZ');
});

test('isSignedPlayAuth detects signed token', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'isSignedPlayAuth');
    $method->setAccessible(true);

    // Build a fake signed playAuth:
    // sign1 "493vpa" must appear at position 20 (year 2026 / 100 = 20)
    // sign2 "ZZ" must appear at the end
    $prefix = str_repeat('A', 20); // 20 chars before sign1
    $sign1 = '493vpa';
    $middle = str_repeat('B', 10); // some content
    $sign2 = 'ZZ';
    $playAuth = $prefix . $sign1 . $middle . $sign2;

    $result = $method->invoke(null, $playAuth);
    expect($result)->toBeTrue();
});

test('isSignedPlayAuth returns false for non-signed token', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'isSignedPlayAuth');
    $method->setAccessible(true);

    $playAuth = str_repeat('A', 50);
    $result = $method->invoke(null, $playAuth);
    expect($result)->toBeFalse();
});

test('isSignedPlayAuth returns false for too-short token', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'isSignedPlayAuth');
    $method->setAccessible(true);

    $result = $method->invoke(null, 'short');
    expect($result)->toBeFalse();
});

test('percentEncode encodes special characters', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'percentEncode');
    $method->setAccessible(true);

    // urlencode encodes space as '+'
    expect($method->invoke(null, 'hello world'))->toBe('hello+world');
    expect($method->invoke(null, '/'))->toBe('%2F');
    expect($method->invoke(null, 'a=b'))->toBe('a%3Db');
});

test('getCqs sorts and joins parameters', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'getCqs');
    $method->setAccessible(true);

    $params = ['C=3', 'A=1', 'B=2'];
    $result = $method->invoke(null, $params);

    expect($result)->toBe('A=1&B=2&C=3');
});

test('getCqs handles single parameter', function () {
    $method = new ReflectionMethod(VodUrlBuilder::class, 'getCqs');
    $method->setAccessible(true);

    $result = $method->invoke(null, ['Only=1']);
    expect($result)->toBe('Only=1');
});
