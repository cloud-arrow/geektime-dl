<?php

declare(strict_types=1);

namespace App\Geektime;

class UniversityApi
{
    private const BASE_URL = 'https://u.geekbang.org';

    // Endpoint paths
    private const V1_MY_CLASS_INFO_PATH = '/serv/v1/myclass/info';

    private const V1_MY_CLASS_ARTICLE_PATH = '/serv/v1/myclass/article';

    private const V1_VIDEO_PLAY_AUTH_PATH = '/serv/v1/video/play-auth';

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get university/training camp class info and all articles.
     *
     * Returns class metadata along with a lessons array, where each lesson
     * contains articles. The Go code also handles error code -5001 to indicate
     * no access, but that is handled at a higher level in PHP.
     *
     * @return array<string, mixed>
     */
    public function productInfo(int $classId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_MY_CLASS_INFO_PATH,
            [
                'class_id' => $classId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get university class article detail.
     *
     * Returns article content including article_content, video_id, type, etc.
     *
     * @return array<string, mixed>
     */
    public function articles(int $classId): array
    {
        // University uses the same endpoint as productInfo to get the lesson/article list.
        // The class info response contains the full lessons hierarchy.
        return $this->productInfo($classId);
    }

    /**
     * Get detailed article content for a university class article.
     *
     * @return array<string, mixed>
     */
    public function articleInfo(int $classId, int $articleId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_MY_CLASS_ARTICLE_PATH,
            [
                'article_id' => $articleId,
                'class_id' => $classId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get university video play auth token.
     *
     * @return array<string, mixed>
     */
    public function videoPlayAuth(int $articleId, int $classId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_VIDEO_PLAY_AUTH_PATH,
            [
                'article_id' => $articleId,
                'class_id' => $classId,
            ],
        );

        return $response['data'] ?? [];
    }
}
