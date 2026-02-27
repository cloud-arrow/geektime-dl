<?php

declare(strict_types=1);

namespace App\Geektime;

class EnterpriseApi
{
    private const BASE_URL = 'https://b.geekbang.org';

    // Endpoint paths
    private const V1_COURSE_INFO_PATH = '/app/v1/course/info';

    private const V1_COURSE_ARTICLES_PATH = '/app/v1/course/articles';

    private const V1_ARTICLE_DETAIL_PATH = '/app/v1/article/detail';

    private const V1_VIDEO_PLAY_AUTH_PATH = '/app/v1/source_auth/video_play_auth';

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get enterprise course product info.
     *
     * @return array<string, mixed>
     */
    public function productInfo(int $productId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_COURSE_INFO_PATH,
            [
                'id' => $productId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get all articles for an enterprise course.
     *
     * Enterprise courses use a sections/articles hierarchy.
     * Each section contains an article_list with nested article objects.
     *
     * @return array<string, mixed>
     */
    public function articles(int $productId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_COURSE_ARTICLES_PATH,
            [
                'id' => $productId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get enterprise course article detail.
     *
     * Note: Enterprise API expects article_id as a string.
     *
     * @return array<string, mixed>
     */
    public function articleInfo(int $articleId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_ARTICLE_DETAIL_PATH,
            [
                'article_id' => (string) $articleId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get enterprise video play auth token.
     *
     * Note: Enterprise API expects aid and video_id as strings.
     *
     * @return array<string, mixed>
     */
    public function videoPlayAuth(int $articleId, string $videoId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_VIDEO_PLAY_AUTH_PATH,
            [
                'aid' => (string) $articleId,
                'video_id' => $videoId,
            ],
        );

        return $response['data'] ?? [];
    }
}
