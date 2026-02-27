<?php

declare(strict_types=1);

namespace App\Geektime\Dto;

class Course
{
    /**
     * @param  Article[]  $articles
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $type,
        public readonly bool $isVideo,
        public readonly bool $access,
        public readonly array $articles = [],
    ) {}

    /**
     * Create a Course instance from an associative array (e.g., API response data).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $articles = [];
        if (isset($data['articles']) && is_array($data['articles'])) {
            $articles = array_map(
                fn (array $article) => Article::fromArray($article),
                $data['articles']
            );
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            isVideo: (bool) ($data['is_video'] ?? false),
            access: (bool) ($data['access'] ?? false),
            articles: $articles,
        );
    }

    /**
     * Check if this is a text-based course (not a video course).
     */
    public function isTextCourse(): bool
    {
        return ! $this->isVideo;
    }

    /**
     * Return a new Course instance with the given articles attached.
     *
     * @param  Article[]  $articles
     */
    public function withArticles(array $articles): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            type: $this->type,
            isVideo: $this->isVideo,
            access: $this->access,
            articles: $articles,
        );
    }
}
