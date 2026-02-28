<?php

declare(strict_types=1);

use App\Vod\PlayInfo;

test('fromArray maps all fields correctly', function () {
    $info = PlayInfo::fromArray([
        'Format' => 'mp4',
        'BitDepth' => 8,
        'PlayURL' => 'https://example.com/video.mp4',
        'Width' => 1920,
        'Height' => 1080,
        'Size' => 1024000,
        'Encrypt' => 1,
        'HDRType' => 'HDR10',
        'Duration' => '120.5',
        'Bitrate' => '2500',
        'Fps' => '30',
        'Status' => 'Normal',
    ]);
    expect($info->format)->toBe('mp4');
    expect($info->bitDepth)->toBe(8);
    expect($info->playUrl)->toBe('https://example.com/video.mp4');
    expect($info->width)->toBe(1920);
    expect($info->height)->toBe(1080);
    expect($info->size)->toBe(1024000);
    expect($info->encrypt)->toBe(1);
    expect($info->hdrType)->toBe('HDR10');
    expect($info->duration)->toBe('120.5');
    expect($info->bitrate)->toBe('2500');
    expect($info->fps)->toBe('30');
    expect($info->status)->toBe('Normal');
});

test('fromArray uses defaults for missing fields', function () {
    $info = PlayInfo::fromArray([]);
    expect($info->format)->toBe('');
    expect($info->bitDepth)->toBe(0);
    expect($info->playUrl)->toBe('');
    expect($info->width)->toBe(0);
    expect($info->height)->toBe(0);
    expect($info->size)->toBe(0);
    expect($info->encrypt)->toBe(0);
});
