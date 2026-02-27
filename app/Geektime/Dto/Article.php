<?php

declare(strict_types=1);

namespace App\Geektime\Dto;

class Article
{
    public function __construct(
        public readonly int $aid,
        public readonly string $title,
        public readonly string $sectionTitle = '',
        public readonly string $content = '',
        public readonly string $audioDownloadUrl = '',
        public readonly string $videoId = '',
        public readonly bool $isVideo = false,
        public readonly int $inPvip = 0,
        /** @var array<int, array{video_url: string}> */
        public readonly array $inlineVideoSubtitles = [],
    ) {}

    /**
     * Create an Article instance from an associative array (e.g., API response data).
     *
     * Supports both the article list format (V1ColumnArticles) and
     * the article detail format (V1Article / V3ArticleInfo).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            aid: (int) ($data['aid'] ?? $data['id'] ?? $data['article_id'] ?? 0),
            title: (string) ($data['title'] ?? $data['article_title'] ?? ''),
            sectionTitle: (string) ($data['section_title'] ?? $data['chapter_name'] ?? ''),
            content: (string) ($data['content'] ?? $data['article_content'] ?? ''),
            audioDownloadUrl: (string) ($data['audio_download_url'] ?? ''),
            videoId: (string) ($data['video_id'] ?? $data['video']['id'] ?? ''),
            isVideo: (bool) ($data['is_video'] ?? false),
            inPvip: (int) ($data['in_pvip'] ?? 0),
            inlineVideoSubtitles: (array) ($data['inline_video_subtitles'] ?? []),
        );
    }
}
