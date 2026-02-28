<?php

declare(strict_types=1);

namespace App\Geektime;

use App\Geektime\Exceptions\ApiException;

class UniversityApi
{
    private const BASE_URL = 'https://u.geekbang.org';

    // Endpoint paths
    private const V1_MY_CLASS_INFO_PATH = '/serv/v1/myclass/info';

    private const V1_MY_CLASS_ARTICLE_PATH = '/serv/v1/myclass/article';

    private const V1_VIDEO_PLAY_AUTH_PATH = '/serv/v1/video/play-auth';

    /**
     * Error code indicating the user has not purchased/has no access to the class.
     * Matches Go: university.go line 43 — res.Error.Code == -5001
     */
    private const ERROR_NO_ACCESS = -5001;

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get university/training camp class info and all articles.
     *
     * Handles error code -5001 gracefully by returning an array with
     * 'access' => false, matching Go's UniversityClassInfo behavior.
     *
     * @return array<string, mixed> Class data, or ['access' => false] if no access
     */
    public function productInfo(int $classId): array
    {
        try {
            $response = $this->client->post(
                self::BASE_URL.self::V1_MY_CLASS_INFO_PATH,
                [
                    'class_id' => $classId,
                ],
            );

            return $response['data'] ?? [];
        } catch (ApiException $e) {
            // Match Go: university.go lines 42-46
            // When error code is -5001, return empty data with access=false
            // instead of throwing an exception
            if ($this->isNoAccessError($e)) {
                return ['access' => false];
            }

            throw $e;
        }
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
            self::BASE_URL.self::V1_MY_CLASS_ARTICLE_PATH,
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
            self::BASE_URL.self::V1_VIDEO_PLAY_AUTH_PATH,
            [
                'article_id' => $articleId,
                'class_id' => $classId,
            ],
        );

        return $response['data'] ?? [];
    }

    /**
     * Check if an ApiException indicates no-access error code -5001.
     *
     * The API may return the error code in different positions:
     * - Top-level: {"code": -5001, ...}
     * - Nested: {"code": -1, "error": {"code": -5001, ...}}
     */
    private function isNoAccessError(ApiException $e): bool
    {
        $body = json_decode($e->responseBody, true);
        if (! is_array($body)) {
            return false;
        }

        $topCode = (int) ($body['code'] ?? 0);
        $errorCode = (int) ($body['error']['code'] ?? 0);

        return $topCode === self::ERROR_NO_ACCESS || $errorCode === self::ERROR_NO_ACCESS;
    }
}
