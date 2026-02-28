<?php

declare(strict_types=1);

namespace App\Downloader;

use App\Crypto\AesCrypto;
use App\Geektime\Dto\Course;
use App\Geektime\EnterpriseApi;
use App\Geektime\GeektimeApi;
use App\Geektime\UniversityApi;
use App\Http\FileDownloader;
use App\M3u8\M3u8Parser;
use App\M3u8\TsParser;
use App\Support\FileHelper;
use App\Support\Filenamify;
use App\Vod\PlayInfo;
use App\Vod\VodUrlBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Downloads video articles as .ts files.
 *
 * Ported from Go internal/video/video.go.
 *
 * Handles the full video download pipeline:
 * 1. Get video play auth from the API
 * 2. Build VOD URL using VodUrlBuilder
 * 3. Fetch VOD API response to get PlayInfo list
 * 4. Select best quality video
 * 5. Download M3U8 playlist, parse segments, decrypt if needed, and merge
 */
final class VideoDownloader
{
    private const TS_EXTENSION = '.ts';

    private const DEFAULT_BASE_URL = 'https://time.geekbang.org';

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36';

    public function __construct(
        private readonly FileDownloader $fileDownloader,
        private readonly ?GuzzleClient $httpClient = null,
    ) {}

    /**
     * Download a normal video article.
     *
     * Matches Go's DownloadArticleVideo.
     *
     * @param  GeektimeApi  $api  Geektime API client
     * @param  int  $articleId  Article ID
     * @param  int  $sourceType  Source type (1 for normal video courses)
     * @param  string  $projectDir  Output directory
     * @param  string  $quality  Desired quality (ld/sd/hd)
     * @param  int  $concurrency  Download concurrency
     */
    public function downloadArticleVideo(
        GeektimeApi $api,
        int $articleId,
        int $sourceType,
        string $projectDir,
        string $quality,
        int $concurrency,
    ): void {
        Log::info('Begin download normal article video', ['articleID' => $articleId, 'sourceType' => $sourceType]);

        $articleInfo = $api->v3ArticleInfo($articleId);
        $videoId = (string) ($articleInfo['info']['video']['id'] ?? '');

        if ($videoId === '') {
            Log::info('No video ID found for article, skipping', ['articleID' => $articleId]);

            return;
        }

        $infoId = (int) ($articleInfo['info']['id'] ?? $articleId);
        $title = (string) ($articleInfo['info']['title'] ?? '');

        // Note: Go's VideoPlayAuth takes (articleID, sourceType int, videoID string).
        // The PHP GeektimeApi::videoPlayAuth(int, int) currently hardcodes source_type=1
        // and accepts videoId as int. This should be updated to accept string $videoId
        // and int $sourceType to fully match the Go implementation.
        $playAuthData = $api->videoPlayAuth($infoId, $videoId);
        $playAuth = (string) ($playAuthData['play_auth'] ?? '');

        $this->downloadAliyunVodEncryptVideo(
            $playAuth,
            $title,
            $projectDir,
            $quality,
            $videoId,
            $concurrency,
        );

        Log::info('Finish download normal article video', ['articleID' => $articleId]);
    }

    /**
     * Download an enterprise video article.
     *
     * Matches Go's DownloadEnterpriseArticleVideo.
     *
     * @param  EnterpriseApi  $api  Enterprise API client
     * @param  int  $articleId  Article ID
     * @param  string  $projectDir  Output directory
     * @param  string  $quality  Desired quality (ld/sd/hd)
     * @param  int  $concurrency  Download concurrency
     */
    public function downloadEnterpriseArticleVideo(
        EnterpriseApi $api,
        int $articleId,
        string $projectDir,
        string $quality,
        int $concurrency,
    ): void {
        Log::info('Begin download enterprise article video', ['articleID' => $articleId]);

        $articleInfo = $api->articleInfo($articleId);
        $videoId = (string) ($articleInfo['video']['id'] ?? '');

        if ($videoId === '') {
            Log::info('No video ID found for enterprise article, skipping', ['articleID' => $articleId]);

            return;
        }

        $title = (string) ($articleInfo['article']['title'] ?? '');

        $playAuthData = $api->videoPlayAuth($articleId, $videoId);
        $playAuth = (string) ($playAuthData['play_auth'] ?? '');

        $this->downloadAliyunVodEncryptVideo(
            $playAuth,
            $title,
            $projectDir,
            $quality,
            $videoId,
            $concurrency,
        );

        Log::info('Finish download enterprise article video', ['articleID' => $articleId]);
    }

    /**
     * Download a university video article.
     *
     * Matches Go's DownloadUniversityVideo.
     *
     * @param  UniversityApi  $api  University API client
     * @param  int  $articleId  Article ID
     * @param  Course  $course  Current course
     * @param  string  $projectDir  Output directory
     * @param  string  $quality  Desired quality (ld/sd/hd)
     * @param  int  $concurrency  Download concurrency
     */
    public function downloadUniversityVideo(
        UniversityApi $api,
        int $articleId,
        Course $course,
        string $projectDir,
        string $quality,
        int $concurrency,
    ): void {
        Log::info('Begin download university article video', ['articleID' => $articleId]);

        $playAuthInfo = $api->videoPlayAuth($articleId, $course->id);
        $playAuth = (string) ($playAuthInfo['play_auth'] ?? '');
        $videoId = (string) ($playAuthInfo['vid'] ?? '');

        $videoTitle = $this->getUniversityVideoTitle($articleId, $course);

        $this->downloadAliyunVodEncryptVideo(
            $playAuth,
            $videoTitle,
            $projectDir,
            $quality,
            $videoId,
            $concurrency,
        );

        Log::info('Finish download university article video', ['articleID' => $articleId]);
    }

    /**
     * Download MP4 resources found inline in article content.
     *
     * Matches Go's DownloadMP4.
     *
     * @param  string  $title  Article title
     * @param  string  $projectDir  Output directory
     * @param  string[]  $mp4Urls  List of MP4 URLs to download
     * @param  bool  $overwrite  Whether to overwrite existing files
     */
    public function downloadMp4(string $title, string $projectDir, array $mp4Urls, bool $overwrite = false): void
    {
        Log::info('Begin download article mp4 videos', ['title' => $title, 'mp4URLs' => $mp4Urls]);

        $filenamifyTitle = Filenamify::filenamify($title);
        $videoDir = $projectDir.DIRECTORY_SEPARATOR.'videos'.DIRECTORY_SEPARATOR.$filenamifyTitle;

        if (! is_dir($videoDir) && ! mkdir($videoDir, 0o777, true) && ! is_dir($videoDir)) {
            Log::error('Failed to create video directory', ['dir' => $videoDir]);

            return;
        }

        $headers = [
            'Origin' => self::DEFAULT_BASE_URL,
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ];

        foreach ($mp4Urls as $mp4Url) {
            $parsed = parse_url($mp4Url);
            $baseName = basename($parsed['path'] ?? '');
            $dst = $videoDir.DIRECTORY_SEPARATOR.$baseName;

            if (FileHelper::checkFileExists($dst) && ! $overwrite) {
                continue;
            }

            Log::info('Begin download single article mp4 video', ['title' => $title, 'mp4URL' => $mp4Url]);

            try {
                $this->fileDownloader->download($dst, $mp4Url, $headers, 5);
                Log::info('Finish download single article mp4 video', ['title' => $title, 'mp4URL' => $mp4Url]);
            } catch (\Throwable $e) {
                Log::error('Failed to download single article mp4 video', [
                    'title' => $title,
                    'mp4URL' => $mp4Url,
                    'error' => $e->getMessage(),
                ]);
                // Match Go behavior: log error but continue (return nil in Go)
            }
        }

        Log::info('Finish download all article mp4 videos', ['title' => $title]);
    }

    /**
     * Check if a video file already exists for the given article.
     *
     * @param  string  $articleTitle  Article title
     * @param  string  $columnDir  Course output directory
     * @return bool True if file already exists
     */
    public function videoFileExists(string $articleTitle, string $columnDir): bool
    {
        $fileName = Filenamify::filenamify($articleTitle).self::TS_EXTENSION;
        $fullPath = $columnDir.DIRECTORY_SEPARATOR.$fileName;

        return FileHelper::checkFileExists($fullPath);
    }

    /**
     * Download Aliyun VOD encrypted video.
     *
     * Matches Go's downloadAliyunVodEncryptVideo.
     *
     * @param  string  $playAuth  Play auth token
     * @param  string  $videoTitle  Title for the output file
     * @param  string  $projectDir  Output directory
     * @param  string  $quality  Desired quality (ld/sd/hd)
     * @param  string  $videoId  Video ID
     * @param  int  $concurrency  Download concurrency
     */
    private function downloadAliyunVodEncryptVideo(
        string $playAuth,
        string $videoTitle,
        string $projectDir,
        string $quality,
        string $videoId,
        int $concurrency,
    ): void {
        $clientRand = Str::uuid()->toString();
        $playInfoUrl = VodUrlBuilder::buildUrl($playAuth, $videoId, $clientRand);
        $playInfo = $this->getPlayInfo($playInfoUrl, $quality);

        if ($playInfo->playUrl === '') {
            Log::warning('No play URL found for video', ['videoId' => $videoId, 'quality' => $quality]);

            return;
        }

        $tsUrlPrefix = $this->extractTsUrlPrefix($playInfo->playUrl);

        // Download and parse M3U8 playlist
        $m3u8Content = $this->fileDownloader->downloadContent($playInfo->playUrl, [
            'Origin' => self::DEFAULT_BASE_URL,
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ]);

        $parsed = M3u8Parser::parse($m3u8Content);
        $tsFileNames = $parsed['tsFileNames'];
        $isVodEncryptVideo = $parsed['isVodEncryptVideo'];

        $decryptKey = '';
        if ($isVodEncryptVideo) {
            $decryptKey = AesCrypto::getAesDecryptKey($clientRand, $playInfo->rand, $playInfo->plaintext);
        }

        $this->downloadAndMerge(
            $tsUrlPrefix,
            $videoTitle,
            $projectDir,
            $tsFileNames,
            $decryptKey,
            $isVodEncryptVideo,
            $concurrency,
            $playInfo->size,
        );
    }

    /**
     * Download TS segments, optionally decrypt, and merge into a single .ts file.
     *
     * Matches Go's download + mergeTSFiles functions.
     * Shows a progress bar during download (matching Go's pb.ProgressBar).
     *
     * @param  string  $tsUrlPrefix  URL prefix for TS segments
     * @param  string  $title  Video title for output filename
     * @param  string  $projectDir  Output directory
     * @param  string[]  $tsFileNames  List of TS segment filenames
     * @param  string  $decryptKey  Hex-encoded AES decrypt key (empty if not encrypted)
     * @param  bool  $isVodEncryptVideo  Whether segments need decryption
     * @param  int  $concurrency  Download concurrency per segment
     * @param  int  $totalSize  Expected total file size in bytes (from PlayInfo.Size)
     */
    private function downloadAndMerge(
        string $tsUrlPrefix,
        string $title,
        string $projectDir,
        array $tsFileNames,
        string $decryptKey,
        bool $isVodEncryptVideo,
        int $concurrency,
        int $totalSize = 0,
    ): void {
        $filenamifyTitle = Filenamify::filenamify($title);

        // Create temp directory for TS segments
        $tempVideoDir = $projectDir.DIRECTORY_SEPARATOR.$filenamifyTitle;
        if (! is_dir($tempVideoDir) && ! mkdir($tempVideoDir, 0o777, true) && ! is_dir($tempVideoDir)) {
            Log::error('Failed to create temp video directory', ['dir' => $tempVideoDir]);

            return;
        }

        $headers = [
            'Origin' => self::DEFAULT_BASE_URL,
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ];

        // Create progress bar matching Go's newBar(size, prefix)
        $bar = $this->createProgressBar($filenamifyTitle, $totalSize);
        $bar?->start();

        try {
            // Download each TS segment sequentially
            foreach ($tsFileNames as $tsFileName) {
                $url = $tsUrlPrefix.$tsFileName;
                $dst = $tempVideoDir.DIRECTORY_SEPARATOR.$tsFileName;

                $fileSize = $this->fileDownloader->download($dst, $url, $headers, $concurrency);

                // Advance progress bar matching Go's addBarValue
                $this->advanceProgressBar($bar, $fileSize, $totalSize);
            }

            $bar?->finish();
            // Move to next line after progress bar
            if ($bar !== null) {
                fwrite(STDERR, "\n");
            }

            // Merge TS files into final output
            $this->mergeTsFiles($tempVideoDir, $filenamifyTitle, $projectDir, $decryptKey, $isVodEncryptVideo);
        } finally {
            // Clean up temp directory (matches Go's defer os.RemoveAll)
            $this->removeDirectory($tempVideoDir);
        }
    }

    /**
     * Create a progress bar for video download.
     *
     * Matches Go's newBar: shows prefix, bytes progress with SI units, simple template.
     *
     * @param  string  $title  Sanitized video title for the prefix
     * @param  int  $totalSize  Expected total size in bytes
     * @return ProgressBar|null Returns null if output is not available
     */
    private function createProgressBar(string $title, int $totalSize): ?ProgressBar
    {
        if ($totalSize <= 0) {
            return null;
        }

        try {
            $output = new ConsoleOutput();
            $bar = new ProgressBar($output->getErrorOutput(), $totalSize);

            // Match Go's pb.Simple template: prefix + bar + current/total
            $bar->setFormat("[正在下载 {$title}] %current_size% / %total_size% [%bar%] %percent:3s%%");

            // Custom placeholders for human-readable sizes
            ProgressBar::setPlaceholderFormatterDefinition('current_size', function (ProgressBar $bar) {
                return self::formatBytes($bar->getProgress());
            });
            ProgressBar::setPlaceholderFormatterDefinition('total_size', function (ProgressBar $bar) {
                return self::formatBytes($bar->getMaxSteps());
            });

            $bar->setBarWidth(20);
            $bar->setRedrawFrequency(1);

            return $bar;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Advance progress bar by the downloaded file size.
     *
     * Matches Go's addBarValue: caps at total to avoid overflow.
     */
    private function advanceProgressBar(?ProgressBar $bar, int $written, int $total): void
    {
        if ($bar === null || $written <= 0) {
            return;
        }

        // Match Go: if current + written > total, set to total
        if ($bar->getProgress() + $written > $total) {
            $bar->setProgress($total);
        } else {
            $bar->advance($written);
        }
    }

    /**
     * Format bytes into human-readable SI format.
     *
     * Matches Go's pb SIBytesPrefix style (e.g. "12.5 MiB").
     */
    private static function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $bytes = max(0, $bytes);

        $pow = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        $value = $bytes / (1024 ** $pow);

        return sprintf('%.1f %s', $value, $units[$pow]);
    }

    /**
     * Merge individual TS segment files into a single output file.
     *
     * If the video is encrypted, each segment is decrypted using TsParser before merging.
     * Matches Go's mergeTSFiles function.
     *
     * @param  string  $tempVideoDir  Directory containing downloaded TS segments
     * @param  string  $filenamifyTitle  Sanitized title for the output filename
     * @param  string  $projectDir  Output directory
     * @param  string  $decryptKey  Hex-encoded AES key (empty if unencrypted)
     * @param  bool  $isVodEncryptVideo  Whether segments need decryption
     */
    private function mergeTsFiles(
        string $tempVideoDir,
        string $filenamifyTitle,
        string $projectDir,
        string $decryptKey,
        bool $isVodEncryptVideo,
    ): void {
        $fullPath = $projectDir.DIRECTORY_SEPARATOR.$filenamifyTitle.self::TS_EXTENSION;

        // Read TS segment files in order
        $tempFiles = scandir($tempVideoDir);
        if ($tempFiles === false) {
            Log::error('Failed to read temp video directory', ['dir' => $tempVideoDir]);

            return;
        }

        // Filter out . and .. entries, sort to ensure correct order
        $tempFiles = array_values(array_filter($tempFiles, fn (string $f) => $f !== '.' && $f !== '..'));
        sort($tempFiles);

        $fp = fopen($fullPath, 'wb');
        if ($fp === false) {
            Log::error('Failed to open output file for writing', ['path' => $fullPath]);

            return;
        }

        $removeOnError = false;

        try {
            foreach ($tempFiles as $tempFile) {
                $segmentPath = $tempVideoDir.DIRECTORY_SEPARATOR.$tempFile;
                $data = file_get_contents($segmentPath);

                if ($data === false) {
                    $removeOnError = true;
                    Log::error('Failed to read TS segment file', ['file' => $segmentPath]);

                    return;
                }

                if ($isVodEncryptVideo) {
                    try {
                        $tsParser = new TsParser($data, $decryptKey);
                        $data = $tsParser->decrypt();
                    } catch (\Throwable $e) {
                        $removeOnError = true;
                        Log::error('Failed to decrypt TS segment', [
                            'file' => $segmentPath,
                            'error' => $e->getMessage(),
                        ]);

                        return;
                    }
                }

                fwrite($fp, $data);
            }
        } finally {
            fclose($fp);

            if ($removeOnError) {
                @unlink($fullPath);
            }
        }
    }

    /**
     * Fetch PlayInfo from VOD API and select the matching quality.
     *
     * Matches Go's getPlayInfo function.
     * Iterates through all play info entries and selects the one matching
     * the requested quality. Falls back to the first available if no match.
     *
     * @param  string  $playInfoUrl  Full signed VOD API URL
     * @param  string  $quality  Desired quality (ld/sd/hd)
     * @return PlayInfo Selected play info
     */
    private function getPlayInfo(string $playInfoUrl, string $quality): PlayInfo
    {
        $client = $this->httpClient ?? new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $response = $client->get($playInfoUrl);
        $body = json_decode((string) $response->getBody(), true);

        $playInfoList = $body['PlayInfoList']['PlayInfo'] ?? [];

        $selectedPlayInfo = new PlayInfo();

        foreach ($playInfoList as $p) {
            if (strcasecmp((string) ($p['Definition'] ?? ''), $quality) === 0) {
                $selectedPlayInfo = PlayInfo::fromArray($p);
            }
        }

        // If no matching quality found, fall back to the first available
        if ($selectedPlayInfo->playUrl === '' && ! empty($playInfoList)) {
            $selectedPlayInfo = PlayInfo::fromArray($playInfoList[0]);
        }

        return $selectedPlayInfo;
    }

    /**
     * Extract the URL prefix for TS segments from an M3U8 URL.
     *
     * Takes everything up to and including the last '/'.
     * Matches Go's extractTSURLPrefix.
     */
    private function extractTsUrlPrefix(string $m3u8Url): string
    {
        $lastSlash = strrpos($m3u8Url, '/');
        if ($lastSlash === false) {
            return $m3u8Url;
        }

        return substr($m3u8Url, 0, $lastSlash + 1);
    }

    /**
     * Get the video title for a university article by looking it up in the course articles.
     *
     * Matches Go's getUniversityVideoTitle.
     */
    private function getUniversityVideoTitle(int $articleId, Course $course): string
    {
        foreach ($course->articles as $article) {
            if ($article->aid === $articleId) {
                return $article->title;
            }
        }

        return '';
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * Matches Go's os.RemoveAll behavior.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
