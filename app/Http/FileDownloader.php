<?php

declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * File downloader with concurrent chunk downloading and retry support.
 *
 * Ported from Go internal/pkg/downloader/downloader.go.
 *
 * Downloads files in chunks using HTTP Range requests for concurrency,
 * with exponential backoff retry on failure.
 */
final class FileDownloader
{
    private const DEFAULT_RETRY_ATTEMPTS = 3;

    private const DEFAULT_RETRY_DELAY_MS = 700;

    private const DEFAULT_CONCURRENCY = 5;

    private GuzzleClient $httpClient;

    public function __construct(?GuzzleClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new GuzzleClient([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Download a file from URL to a local path using concurrent range requests.
     *
     * Matches Go's DownloadFileConcurrently:
     * 1. HEAD request to get Content-Length
     * 2. Split into chunks based on concurrency
     * 3. Download each chunk with range headers
     * 4. Write chunks to file in order
     *
     * @param  string  $filepath  Local file path to save to
     * @param  string  $url  URL to download from
     * @param  array<string, string>  $headers  Optional HTTP headers
     * @param  int  $concurrency  Number of concurrent download chunks
     * @return int File size in bytes (0 if file is empty or Content-Length unavailable)
     *
     * @throws RuntimeException If download fails after retries
     */
    public function download(
        string $filepath,
        string $url,
        array $headers = [],
        int $concurrency = self::DEFAULT_CONCURRENCY,
    ): int {
        // Get file size via HEAD request
        $fileSize = $this->getContentLength($url, $headers);

        if ($fileSize <= 0) {
            // Create empty file, matching Go behavior
            $this->ensureDirectoryExists($filepath);
            file_put_contents($filepath, '');

            return 0;
        }

        if ($concurrency <= 0) {
            $concurrency = 1;
        }
        if ($concurrency > $fileSize) {
            $concurrency = (int) $fileSize;
        }

        $chunkSize = intdiv($fileSize, $concurrency);
        $parts = [];

        // Download each chunk with retry
        for ($i = 0; $i < $concurrency; $i++) {
            $start = $i * $chunkSize;

            if ($i === $concurrency - 1) {
                // Last chunk gets the rest of the file
                $rangeHeader = sprintf('bytes=%d-', $start);
            } else {
                $rangeHeader = sprintf('bytes=%d-%d', $start, $start + $chunkSize - 1);
            }

            $data = $this->downloadChunk($url, $rangeHeader, $headers, $i);
            $parts[$i] = ['data' => $data, 'offset' => $start];
        }

        // Write all parts to file in order
        $this->ensureDirectoryExists($filepath);
        $fp = fopen($filepath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Failed to open file for writing: '.$filepath);
        }

        try {
            // Sort by index to ensure correct order
            ksort($parts);
            foreach ($parts as $part) {
                fseek($fp, (int) $part['offset']);
                fwrite($fp, $part['data']);
            }
        } finally {
            fclose($fp);
        }

        return $fileSize;
    }

    /**
     * Simple single-request download (no chunking). Useful for small files like TS segments.
     *
     * @param  string  $filepath  Local file path to save to
     * @param  string  $url  URL to download from
     * @param  array<string, string>  $headers  Optional HTTP headers
     * @return int Bytes written
     *
     * @throws RuntimeException If download fails after retries
     */
    public function downloadSimple(
        string $filepath,
        string $url,
        array $headers = [],
    ): int {
        $data = $this->retry(function () use ($url, $headers): string {
            $response = $this->httpClient->get($url, [
                'headers' => $headers,
            ]);

            return (string) $response->getBody();
        });

        $this->ensureDirectoryExists($filepath);
        $bytes = file_put_contents($filepath, $data);

        if ($bytes === false) {
            throw new RuntimeException('Failed to write file: '.$filepath);
        }

        return $bytes;
    }

    /**
     * Download content from a URL and return it as a string.
     *
     * @param  string  $url  URL to download from
     * @param  array<string, string>  $headers  Optional HTTP headers
     * @return string Downloaded content
     *
     * @throws RuntimeException If download fails after retries
     */
    public function downloadContent(
        string $url,
        array $headers = [],
    ): string {
        return $this->retry(function () use ($url, $headers): string {
            $response = $this->httpClient->get($url, [
                'headers' => $headers,
            ]);

            return (string) $response->getBody();
        });
    }

    /**
     * Get Content-Length from a HEAD request.
     *
     * @param  array<string, string>  $headers
     * @return int File size in bytes, or 0 if unavailable
     */
    private function getContentLength(string $url, array $headers): int
    {
        try {
            $response = $this->httpClient->head($url, [
                'headers' => $headers,
            ]);

            $contentLength = $response->getHeaderLine('Content-Length');

            return (int) $contentLength;
        } catch (GuzzleException $e) {
            Log::warning('HEAD request failed, will try download anyway', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Download a single chunk with a Range header.
     *
     * @param  string  $range  Range header value (e.g. "bytes=0-1023")
     * @param  array<string, string>  $extraHeaders
     * @param  int  $index  Chunk index for logging
     * @return string Downloaded data
     */
    private function downloadChunk(string $url, string $range, array $extraHeaders, int $index): string
    {
        return $this->retry(function () use ($url, $range, $extraHeaders): string {
            $requestHeaders = array_merge($extraHeaders, ['Range' => $range]);

            $response = $this->httpClient->get($url, [
                'headers' => $requestHeaders,
            ]);

            return (string) $response->getBody();
        }, $index);
    }

    /**
     * Retry a callable with exponential backoff.
     *
     * Matches Go's retry function: 3 attempts, starting at 700ms, doubling each time.
     *
     * @template T
     *
     * @param  callable(): T  $fn  The operation to retry
     * @param  int  $context  Optional context for logging (e.g. chunk index)
     * @return T
     *
     * @throws RuntimeException If all attempts fail
     */
    private function retry(callable $fn, int $context = 0): mixed
    {
        $attempts = self::DEFAULT_RETRY_ATTEMPTS;
        $sleepMs = self::DEFAULT_RETRY_DELAY_MS;
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                Log::info('Retry happening', ['times' => $i, 'context' => $context]);
                usleep($sleepMs * 1000);
                $sleepMs *= 2;
            }

            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('Download attempt failed', [
                    'attempt' => $i + 1,
                    'context' => $context,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException(
            sprintf('After %d attempts, last error: %s', $attempts, $lastException?->getMessage() ?? 'unknown'),
            previous: $lastException
        );
    }

    /**
     * Ensure the parent directory of a file path exists.
     */
    private function ensureDirectoryExists(string $filepath): void
    {
        $dir = dirname($filepath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, recursive: true);
        }
    }
}
