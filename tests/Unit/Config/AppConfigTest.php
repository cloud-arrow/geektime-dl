<?php

declare(strict_types=1);

use App\Config\AppConfig;

test('readCookiesFromInput parses GCID and GCESS', function () {
    $config = new AppConfig();
    $config->readCookiesFromInput('GCID=abc123; GCESS=def456');
    expect($config->gcid)->toBe('abc123');
    expect($config->gcess)->toBe('def456');
});

test('readCookiesFromInput ignores irrelevant cookies', function () {
    $config = new AppConfig();
    $config->readCookiesFromInput('GCID=abc; other=xyz; GCESS=def');
    expect($config->gcid)->toBe('abc');
    expect($config->gcess)->toBe('def');
});

test('validate passes with valid configuration', function () {
    $config = new AppConfig(gcid: 'abc', gcess: 'def');
    $config->validate(); // should not throw
    expect(true)->toBeTrue();
});

test('validate fails with empty cookies', function () {
    $config = new AppConfig();
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with invalid quality', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', quality: '4k');
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with invalid output type 0', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', columnOutputType: 0);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with invalid output type 8', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', columnOutputType: 8);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with invalid comments value', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', downloadComments: 3);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with invalid log level', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', logLevel: 'trace');
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with negative interval', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', interval: -1);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with interval exceeding 10', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', interval: 11);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with printPdfTimeoutSeconds 0', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', printPdfTimeoutSeconds: 0);
    $config->validate();
})->throws(InvalidArgumentException::class);

test('validate fails with printPdfTimeoutSeconds 121', function () {
    $config = new AppConfig(gcid: 'a', gcess: 'b', printPdfTimeoutSeconds: 121);
    $config->validate();
})->throws(InvalidArgumentException::class);
