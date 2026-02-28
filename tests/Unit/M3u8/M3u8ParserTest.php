<?php

declare(strict_types=1);

use App\M3u8\M3u8Parser;

test('parse extracts ts filenames from basic playlist', function () {
    $content = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXTINF:10.0,
segment001.ts
#EXTINF:10.0,
segment002.ts
#EXT-X-ENDLIST
M3U8;

    $result = M3u8Parser::parse($content);

    expect($result['tsFileNames'])->toBe(['segment001.ts', 'segment002.ts']);
    expect($result['isVodEncryptVideo'])->toBeFalse();
});

test('parse detects AES-128 encryption', function () {
    $content = <<<'M3U8'
#EXTM3U
#EXT-X-KEY:METHOD=AES-128,URI="key.bin",IV=0x1234
#EXTINF:10.0,
enc001.ts
M3U8;

    $result = M3u8Parser::parse($content);

    expect($result['isVodEncryptVideo'])->toBeTrue();
    expect($result['tsFileNames'])->toBe(['enc001.ts']);
});

test('parse returns empty for empty content', function () {
    $result = M3u8Parser::parse('');

    expect($result['tsFileNames'])->toBe([]);
    expect($result['isVodEncryptVideo'])->toBeFalse();
});

test('parse skips comment lines and empty lines', function () {
    $content = <<<'M3U8'
#EXTM3U
# This is a comment
#EXTINF:10.0,

segment001.ts

#EXTINF:10.0,
segment002.ts
#EXT-X-ENDLIST
M3U8;

    $result = M3u8Parser::parse($content);

    expect($result['tsFileNames'])->toBe(['segment001.ts', 'segment002.ts']);
});

test('parse does not flag non-AES encryption', function () {
    $content = <<<'M3U8'
#EXTM3U
#EXT-X-KEY:METHOD=NONE
#EXTINF:10.0,
segment001.ts
M3U8;

    $result = M3u8Parser::parse($content);

    expect($result['isVodEncryptVideo'])->toBeFalse();
});

test('parse only processes first EXT-X-KEY tag', function () {
    $content = <<<'M3U8'
#EXTM3U
#EXT-X-KEY:METHOD=AES-128,URI="key1.bin"
#EXTINF:10.0,
seg1.ts
#EXT-X-KEY:METHOD=NONE
#EXTINF:10.0,
seg2.ts
M3U8;

    $result = M3u8Parser::parse($content);

    // First key is AES-128, second key is ignored
    expect($result['isVodEncryptVideo'])->toBeTrue();
    expect($result['tsFileNames'])->toBe(['seg1.ts', 'seg2.ts']);
});
