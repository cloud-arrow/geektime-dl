<?php

declare(strict_types=1);

namespace App\Geektime;

class GeektimeApi
{
    private const BASE_URL = 'https://time.geekbang.org';

    // Endpoint paths
    private const V1_COLUMN_ARTICLES_PATH = '/serv/v1/column/articles';

    private const V1_ARTICLE_PATH = '/serv/v1/article';

    private const V3_COLUMN_INFO_PATH = '/serv/v3/column/info';

    private const V3_PRODUCT_INFO_PATH = '/serv/v3/product/info';

    private const V3_ARTICLE_INFO_PATH = '/serv/v3/article/info';

    private const V3_VIDEO_PLAY_AUTH_PATH = '/serv/v3/source_auth/video_play_auth';

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get column/course info (v3 column info endpoint).
     *
     * Returns course metadata including id, title, type, is_video, and access info.
     *
     * @return array<string, mixed>
     */
    public function columnInfo(int $productId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V3_COLUMN_INFO_PATH,
            [
                'product_id' => $productId,
                'with_recommend_article' => true,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get all articles in a column, handling pagination.
     *
     * The Go code fetches with size=500 in a single call. We do the same
     * but also handle the `page.more` flag for safety, fetching additional
     * pages if needed using the `prev` parameter (last article ID).
     *
     * @return array<int, array<string, mixed>> List of article summaries
     */
    public function columnArticles(int $courseId): array
    {
        $allArticles = [];
        $prev = 0;

        do {
            $response = $this->client->post(
                self::BASE_URL . self::V1_COLUMN_ARTICLES_PATH,
                [
                    'cid' => (string) $courseId,
                    'order' => 'earliest',
                    'prev' => $prev,
                    'sample' => false,
                    'size' => 500,
                ],
            );

            $data = $response['data'] ?? [];
            $list = $data['list'] ?? [];
            $page = $data['page'] ?? [];

            foreach ($list as $article) {
                $allArticles[] = $article;
            }

            $hasMore = (bool) ($page['more'] ?? false);

            // Update prev to the last article ID for next page
            if ($hasMore && ! empty($list)) {
                $lastArticle = end($list);
                $prev = (int) ($lastArticle['id'] ?? 0);
            }
        } while ($hasMore && ! empty($list));

        return $allArticles;
    }

    /**
     * Get article detail using v3 endpoint.
     *
     * Used for daily lessons, qconplus, and video courses.
     *
     * @return array<string, mixed>
     */
    public function v3ArticleInfo(int $articleId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V3_ARTICLE_INFO_PATH,
            [
                'id' => $articleId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get article detail using v1 endpoint.
     *
     * Used for normal text columns.
     *
     * @return array<string, mixed>
     */
    public function v1ArticleInfo(int $articleId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V1_ARTICLE_PATH,
            [
                'id' => (string) $articleId,
                'include_neighbors' => true,
                'is_freelyread' => true,
                'reverse' => false,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get video play auth token.
     *
     * @return array<string, mixed>
     */
    public function videoPlayAuth(int $articleId, string $videoId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V3_VIDEO_PLAY_AUTH_PATH,
            [
                'aid' => $articleId,
                'source_type' => 1,
                'video_id' => $videoId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Get column info using v3 column info endpoint.
     *
     * This is the same endpoint as columnInfo but named separately
     * for semantic clarity when used in different contexts.
     *
     * @return array<string, mixed>
     */
    public function v3ColumnInfo(int $productId): array
    {
        return $this->columnInfo($productId);
    }

    /**
     * Get product info using v3 product info endpoint.
     *
     * Used for daily lessons and qconplus products.
     *
     * @return array<string, mixed>
     */
    public function productInfo(int $productId): array
    {
        $response = $this->client->post(
            self::BASE_URL . self::V3_PRODUCT_INFO_PATH,
            [
                'id' => $productId,
            ],
        );

        return $response['data'] ?? [];
    }
}
