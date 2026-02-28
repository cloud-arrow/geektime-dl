<?php

declare(strict_types=1);

use App\M3u8\TsParser;

test('throws exception for non-188-byte-multiple data', function () {
    new TsParser('short data', '00112233445566778899aabbccddeeff');
})->throws(RuntimeException::class, 'not multiple of 188');

test('throws exception for invalid sync byte', function () {
    // Create 188 bytes but with wrong sync byte
    $packet = str_repeat("\x00", 188);
    // byte 0 is 0x00, not 0x47

    new TsParser($packet, '00112233445566778899aabbccddeeff');
})->throws(RuntimeException::class, 'Invalid TS package');

test('throws exception for invalid hex key', function () {
    $packet = str_repeat("\x00", 188);
    $packet[0] = "\x47";

    // hex2bin() emits a PHP warning for invalid hex; suppress it so PHPUnit
    // doesn't flag it. The source code uses @hex2bin and throws RuntimeException.
    set_error_handler(fn () => true);
    try {
        new TsParser($packet, 'zz');
    } finally {
        restore_error_handler();
    }
})->throws(RuntimeException::class, 'Failed to decode key hex');

test('parses valid TS packet with video PID', function () {
    // Build a minimal valid 188-byte TS packet with PID 0x100 (video)
    $packet = str_repeat("\x00", 188);
    $packet[0] = "\x47"; // sync byte
    // PID 0x100: high 5 bits of byte1 = 0x01, byte2 = 0x00
    // PUSI = 0 (no payload start indicator), so no PES header parsing
    $packet[1] = "\x01"; // PUSI=0, PID high=0x01
    $packet[2] = "\x00"; // PID low=0x00
    $packet[3] = "\x10"; // AFC=01 (payload only), CC=0

    // This should parse without error. No PES start so no PES header issues.
    $key = '00112233445566778899aabbccddeeff'; // 16-byte key as hex
    $parser = new TsParser($packet, $key);

    // decrypt should work (though data is zeros, ECB decrypt will produce something)
    $result = $parser->decrypt();
    expect(strlen($result))->toBe(188);
});

test('parses multiple TS packets', function () {
    // 2 packets, both with null PID (0x1FFF) to avoid video/audio processing
    $packet = str_repeat("\x00", 188);
    $packet[0] = "\x47"; // sync
    $packet[1] = "\x1F"; // PID high = 0x1F
    $packet[2] = "\xFF"; // PID low = 0xFF → PID = 0x1FFF (null)
    $packet[3] = "\x10"; // AFC=01 payload only

    $data = $packet . $packet; // 376 bytes = 2 * 188

    $key = '00112233445566778899aabbccddeeff';
    $parser = new TsParser($data, $key);
    $result = $parser->decrypt();

    expect(strlen($result))->toBe(376);
});
