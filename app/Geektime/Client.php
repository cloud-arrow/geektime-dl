<?php

declare(strict_types=1);

namespace App\Geektime;

use App\Geektime\Exceptions\ApiException;
use App\Geektime\Exceptions\AuthFailedException;
use App\Geektime\Exceptions\RateLimitException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class Client
{
    private const DEFAULT_TIMEOUT = 10;

    private const RETRY_COUNT = 1;

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36';

    private const COOKIE_DOMAIN = '.geekbang.org';

    private const GCID = 'GCID';

    private const GCESS = 'GCESS';

    private GuzzleClient $httpClient;

    private CookieJar $cookieJar;

    public function __construct(
        private readonly string $gcid,
        private readonly string $gcess,
    ) {
        $this->cookieJar = $this->buildCookieJar();
        $this->httpClient = new GuzzleClient([
            'cookies' => $this->cookieJar,
            'timeout' => self::DEFAULT_TIMEOUT,
            'headers' => [
                'User-Agent' => self::DEFAULT_USER_AGENT,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Send a JSON POST request and return the decoded response body.
     *
     * @param  string  $url  Full URL to POST to
     * @param  array<string, mixed>  $data  Request body data
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws RateLimitException
     * @throws AuthFailedException
     * @throws ApiException
     */
    public function post(string $url, array $data): array
    {
        $origin = $this->extractOrigin($url);

        $lastException = null;

        // Retry logic matching Go's SetRetryCount(1) — try up to 2 times total
        for ($attempt = 0; $attempt <= self::RETRY_COUNT; $attempt++) {
            try {
                Log::debug('HTTP request start', [
                    'method' => 'POST',
                    'url' => $url,
                    'body' => $data,
                    'attempt' => $attempt,
                ]);

                $response = $this->httpClient->post($url, [
                    'json' => $data,
                    'headers' => [
                        'Origin' => $origin,
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                Log::debug('HTTP request end', [
                    'method' => 'POST',
                    'url' => $url,
                    'status_code' => $statusCode,
                ]);

                if ($statusCode !== 200) {
                    Log::warning('HTTP request not ok', ['response_body' => $body]);
                    $this->handleHttpStatusCode($statusCode);
                }

                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    throw ApiException::fromResponse($url, $body);
                }

                $this->checkResponseCode($url, $decoded, $body);

                return $decoded;
            } catch (RateLimitException|AuthFailedException $e) {
                // Do not retry auth/rate limit errors
                throw $e;
            } catch (GuzzleException $e) {
                $statusCode = 0;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    $body = (string) $e->getResponse()->getBody();

                    Log::warning('HTTP request not ok', ['response_body' => $body]);

                    // Check for specific HTTP status codes even from exceptions
                    if ($statusCode === 451) {
                        throw new RateLimitException(previous: $e);
                    }
                    if ($statusCode === 452) {
                        throw new AuthFailedException(previous: $e);
                    }
                }

                $lastException = $e;
                Log::warning('HTTP request failed, will retry', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new ApiException(
            message: sprintf('HTTP request to %s failed after %d attempts', $url, self::RETRY_COUNT + 1),
            path: $url,
            previous: $lastException,
        );
    }

    /**
     * Build cookie jar with GCID and GCESS cookies for the geekbang domain.
     */
    private function buildCookieJar(): CookieJar
    {
        $cookieJar = new CookieJar();

        $cookieJar->setCookie(new SetCookie([
            'Name' => self::GCID,
            'Value' => $this->gcid,
            'Domain' => self::COOKIE_DOMAIN,
            'Path' => '/',
            'Secure' => true,
            'HttpOnly' => true,
        ]));

        $cookieJar->setCookie(new SetCookie([
            'Name' => self::GCESS,
            'Value' => $this->gcess,
            'Domain' => self::COOKIE_DOMAIN,
            'Path' => '/',
            'Secure' => true,
            'HttpOnly' => true,
        ]));

        return $cookieJar;
    }

    /**
     * Extract the origin (scheme + host) from a URL.
     */
    private function extractOrigin(string $url): string
    {
        $parsed = parse_url($url);

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return sprintf('%s://%s', $scheme, $host);
    }

    /**
     * Handle non-200 HTTP status codes.
     *
     * @throws RateLimitException
     * @throws AuthFailedException
     */
    private function handleHttpStatusCode(int $statusCode): void
    {
        match ($statusCode) {
            451 => throw new RateLimitException(),
            452 => throw new AuthFailedException(),
            default => null, // Other non-200 codes fall through to response code check
        };
    }

    /**
     * Check the response body `code` field for error conditions.
     *
     * @param  string  $url  Request URL for error context
     * @param  array<string, mixed>  $decoded  Decoded response body
     * @param  string  $rawBody  Raw response body string
     *
     * @throws AuthFailedException
     * @throws ApiException
     */
    private function checkResponseCode(string $url, array $decoded, string $rawBody): void
    {
        $code = (int) ($decoded['code'] ?? 0);

        if ($code === 0) {
            return;
        }

        Log::warning('HTTP request not ok', ['response_body' => $rawBody]);

        // Auth failed error codes: -3050 (not logged in), -2000 (expired)
        if ($code === -3050 || $code === -2000) {
            throw new AuthFailedException();
        }

        throw ApiException::fromResponse($url, $rawBody);
    }
}
