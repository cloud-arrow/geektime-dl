<?php

declare(strict_types=1);

namespace App\Downloader;

use App\Http\FileDownloader;
use App\Support\FileHelper;
use App\Support\Filenamify;
use Illuminate\Support\Facades\Log;

/**
 * Downloads article audio as MP3 files.
 *
 * Ported from Go internal/audio/audio.go.
 */
final class AudioDownloader
{
    private const MP3_EXTENSION = '.mp3';

    private const DEFAULT_BASE_URL = 'https://time.geekbang.org';

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36';

    public function __construct(
        private readonly FileDownloader $fileDownloader,
    ) {}

    /**
     * Download article audio as an MP3 file.
     *
     * Matches Go's DownloadAudio function:
     * - Skips if audioDownloadUrl is empty
     * - Builds the output filename from the article title
     * - Downloads with Origin and UserAgent headers
     * - Removes the file on failure
     *
     * @param  string  $audioDownloadUrl  URL to download the MP3 from
     * @param  string  $dir  Output directory path
     * @param  string  $title  Article title (used for filename)
     * @param  bool  $overwrite  Whether to overwrite existing files
     */
    public function download(string $audioDownloadUrl, string $dir, string $title, bool $overwrite = false): void
    {
        Log::info('Begin download article audio', ['title' => $title]);

        if ($audioDownloadUrl === '') {
            Log::info('Audio download URL is empty, skipping', ['title' => $title]);

            return;
        }

        $audioFileName = $dir.DIRECTORY_SEPARATOR.Filenamify::filenamify($title).self::MP3_EXTENSION;

        if (! $overwrite && FileHelper::checkFileExists($audioFileName)) {
            Log::info('Audio file already exists, skipping', ['file' => $audioFileName]);

            return;
        }

        $headers = [
            'Origin' => self::DEFAULT_BASE_URL,
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ];

        try {
            $this->fileDownloader->download($audioFileName, $audioDownloadUrl, $headers, 1);
            Log::info('Finish download article audio', ['title' => $title]);
        } catch (\Throwable $e) {
            Log::error('Failed to download article audio', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            // Clean up partial file on failure, matching Go behavior
            if (file_exists($audioFileName)) {
                @unlink($audioFileName);
            }

            throw $e;
        }
    }
}
