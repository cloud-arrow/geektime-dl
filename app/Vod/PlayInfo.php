<?php

declare(strict_types=1);

namespace App\Vod;

/**
 * DTO for Aliyun VOD PlayInfo response.
 *
 * Ported from Go:
 *   internal/video/vod/struct_play_info.go
 *   internal/video/vod/struct_play_info_list_in_get_play_info.go
 *   internal/video/vod/struct_video_base.go
 */
final readonly class PlayInfo
{
    public function __construct(
        public string $format = '',
        public int $bitDepth = 0,
        public string $narrowBandType = '',
        public string $fps = '',
        public int $encrypt = 0,
        public string $rand = '',
        public string $streamType = '',
        public string $watermarkId = '',
        public int $size = 0,
        public string $definition = '',
        public string $plaintext = '',
        public string $jobId = '',
        public string $encryptType = '',
        public string $preprocessStatus = '',
        public string $modificationTime = '',
        public string $bitrate = '',
        public string $creationTime = '',
        public int $height = 0,
        public string $complexity = '',
        public string $duration = '',
        public string $hdrType = '',
        public int $width = 0,
        public string $status = '',
        public string $specification = '',
        public string $playUrl = '',
    ) {}

    /**
     * Create a PlayInfo from a decoded JSON array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            format: (string) ($data['Format'] ?? ''),
            bitDepth: (int) ($data['BitDepth'] ?? 0),
            narrowBandType: (string) ($data['NarrowBandType'] ?? ''),
            fps: (string) ($data['Fps'] ?? ''),
            encrypt: (int) ($data['Encrypt'] ?? 0),
            rand: (string) ($data['Rand'] ?? ''),
            streamType: (string) ($data['StreamType'] ?? ''),
            watermarkId: (string) ($data['WatermarkId'] ?? ''),
            size: (int) ($data['Size'] ?? 0),
            definition: (string) ($data['Definition'] ?? ''),
            plaintext: (string) ($data['Plaintext'] ?? ''),
            jobId: (string) ($data['JobId'] ?? ''),
            encryptType: (string) ($data['EncryptType'] ?? ''),
            preprocessStatus: (string) ($data['PreprocessStatus'] ?? ''),
            modificationTime: (string) ($data['ModificationTime'] ?? ''),
            bitrate: (string) ($data['Bitrate'] ?? ''),
            creationTime: (string) ($data['CreationTime'] ?? ''),
            height: (int) ($data['Height'] ?? 0),
            complexity: (string) ($data['Complexity'] ?? ''),
            duration: (string) ($data['Duration'] ?? ''),
            hdrType: (string) ($data['HDRType'] ?? ''),
            width: (int) ($data['Width'] ?? 0),
            status: (string) ($data['Status'] ?? ''),
            specification: (string) ($data['Specification'] ?? ''),
            playUrl: (string) ($data['PlayURL'] ?? ''),
        );
    }
}
