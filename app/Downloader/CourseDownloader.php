<?php

declare(strict_types=1);

namespace App\Downloader;

use App\Config\AppConfig;
use App\Enums\OutputType;
use App\Geektime\Dto\Article;
use App\Geektime\Dto\Course;
use App\Geektime\EnterpriseApi;
use App\Geektime\GeektimeApi;
use App\Geektime\UniversityApi;
use App\Http\FileDownloader;
use App\Support\Filenamify;
use Illuminate\Support\Facades\Log;

/**
 * Main download orchestrator for Geektime courses.
 *
 * Ported from Go internal/course/downloader.go.
 *
 * Coordinates downloading all articles in a course by:
 * - Creating the output directory structure
 * - Iterating through articles and fetching their details
 * - Delegating to format-specific downloaders (Audio, Markdown, PDF, Video)
 * - Handling text vs video course distinction
 * - Respecting download intervals to avoid rate limiting
 * - Logging errors but continuing with next article
 */
final class CourseDownloader
{
    private readonly AudioDownloader $audioDownloader;

    private readonly MarkdownDownloader $markdownDownloader;

    private readonly PdfDownloader $pdfDownloader;

    private readonly VideoDownloader $videoDownloader;

    private readonly int $concurrency;

    /**
     * Flag to signal cancellation of the download process.
     */
    private bool $cancelled = false;

    public function __construct(
        private readonly AppConfig $config,
        private readonly ?GeektimeApi $geektimeApi = null,
        private readonly ?EnterpriseApi $enterpriseApi = null,
        private readonly ?UniversityApi $universityApi = null,
        ?FileDownloader $fileDownloader = null,
        ?AudioDownloader $audioDownloader = null,
        ?MarkdownDownloader $markdownDownloader = null,
        ?PdfDownloader $pdfDownloader = null,
        ?VideoDownloader $videoDownloader = null,
    ) {
        $fd = $fileDownloader ?? new FileDownloader();
        $this->audioDownloader = $audioDownloader ?? new AudioDownloader($fd);
        $this->markdownDownloader = $markdownDownloader ?? new MarkdownDownloader($fd);
        $this->pdfDownloader = $pdfDownloader ?? new PdfDownloader();
        $this->videoDownloader = $videoDownloader ?? new VideoDownloader($fd);

        // Match Go: concurrency = ceil(NumCPU / 2), min 1
        $numCpu = 1;
        if (function_exists('swoole_cpu_num')) {
            $numCpu = swoole_cpu_num();
        } elseif (is_readable('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if ($cpuInfo !== false) {
                $numCpu = max(1, substr_count($cpuInfo, 'processor'));
            }
        }
        $this->concurrency = max(1, (int) ceil($numCpu / 2.0));
    }

    /**
     * Download all articles in a course.
     *
     * Matches Go's DownloadAll method.
     * For text courses: downloads in PDF/Markdown/Audio format based on config.
     * For video courses: downloads as .ts files.
     *
     * @param  Course  $course  Course with articles to download
     * @param  bool  $isUniversity  Whether this is a university/training camp course
     */
    public function downloadAll(Course $course, bool $isUniversity = false): void
    {
        $columnDir = $this->mkDownloadColumnDir($course->title);

        if ($course->isTextCourse()) {
            $this->downloadAllTextArticles($course, $columnDir);
        } else {
            $this->downloadAllVideoArticles($course, $columnDir, $isUniversity);
        }
    }

    /**
     * Download a single article from a course.
     *
     * Matches Go's DownloadArticle method.
     *
     * @param  Course  $course  The course containing the article
     * @param  Article  $article  The specific article to download
     * @param  bool  $overwrite  Whether to overwrite existing files
     * @param  bool  $isUniversity  Whether this is a university/training camp course
     */
    public function downloadArticle(
        Course $course,
        Article $article,
        bool $overwrite = false,
        bool $isUniversity = false,
    ): void {
        $columnDir = $this->mkDownloadColumnDir($course->title);

        if ($course->isTextCourse()) {
            if (! $overwrite && $this->shouldSkipTextArticle($article, $columnDir)) {
                return;
            }

            $this->downloadTextArticle($article, $columnDir, $overwrite);
        } else {
            if (! $overwrite && $this->videoDownloader->videoFileExists($article->title, $columnDir)) {
                return;
            }

            $this->downloadVideoArticle($course, $article, $columnDir, $isUniversity);
        }
    }

    /**
     * Download a single video product (e.g., daily lessons, case studies).
     *
     * Matches Go's DownloadSingleVideoProduct.
     *
     * @param  string  $title  Product title
     * @param  int  $articleId  Article ID
     * @param  int  $sourceType  Source type
     */
    public function downloadSingleVideoProduct(string $title, int $articleId, int $sourceType): void
    {
        $columnDir = $this->mkDownloadColumnDir($title);

        if ($this->geektimeApi === null) {
            Log::error('GeektimeApi is required for single video product download');

            return;
        }

        try {
            $this->videoDownloader->downloadArticleVideo(
                $this->geektimeApi,
                $articleId,
                $sourceType,
                $columnDir,
                $this->config->quality,
                $this->concurrency,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to download single video product', [
                'title' => $title,
                'articleId' => $articleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Signal cancellation of the download process.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Download all text articles in a course.
     *
     * Shows progress as "completed X/Y" for each article.
     */
    private function downloadAllTextArticles(Course $course, string $columnDir): void
    {
        Log::info("Downloading all articles from course: {$course->title}");

        $total = count($course->articles);
        $downloaded = 0;

        foreach ($course->articles as $article) {
            if ($this->cancelled) {
                Log::info('Download cancelled');

                return;
            }

            if ($this->shouldSkipTextArticle($article, $columnDir)) {
                $downloaded++;
                $this->showProgress($total, $downloaded);

                continue;
            }

            Log::info('Begin download article', [
                'articleID' => $article->aid,
                'articleTitle' => $article->title,
            ]);

            try {
                $this->downloadTextArticle($article, $columnDir, false);
            } catch (\Throwable $e) {
                Log::error('Failed to download text article, continuing with next', [
                    'articleID' => $article->aid,
                    'title' => $article->title,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->waitRandomTime();
            $downloaded++;
            $this->showProgress($total, $downloaded);
        }
    }

    /**
     * Download all video articles in a course.
     */
    private function downloadAllVideoArticles(
        Course $course,
        string $columnDir,
        bool $isUniversity,
    ): void {
        foreach ($course->articles as $article) {
            if ($this->cancelled) {
                Log::info('Download cancelled');

                return;
            }

            // For university courses, check if the article actually has a video
            if ($isUniversity && $this->universityApi !== null) {
                try {
                    $universityArticleDetail = $this->universityApi->articleInfo($course->id, $article->aid);
                    $videoId = (string) ($universityArticleDetail['video_id'] ?? '');
                    if ($videoId === '') {
                        // Skip non-video articles in university courses
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to get university article detail, skipping', [
                        'articleID' => $article->aid,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            if ($this->videoDownloader->videoFileExists($article->title, $columnDir)) {
                continue;
            }

            try {
                $this->downloadVideoArticle($course, $article, $columnDir, $isUniversity);
            } catch (\Throwable $e) {
                Log::error('Failed to download video article, continuing with next', [
                    'articleID' => $article->aid,
                    'title' => $article->title,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->waitRandomTime();
        }
    }

    /**
     * Check if a text article download should be skipped (all requested output files exist).
     *
     * Matches Go's skipDownloadTextArticle logic.
     * Returns true only if ALL requested output types already exist.
     */
    private function shouldSkipTextArticle(Article $article, string $columnDir): bool
    {
        $needPdf = OutputType::PDF->isSetIn($this->config->columnOutputType);
        $needMd = OutputType::Markdown->isSetIn($this->config->columnOutputType);
        $needAudio = OutputType::Audio->isSetIn($this->config->columnOutputType);

        if ($needPdf && ! $this->pdfDownloader->pdfFileExists($article->title, $columnDir)) {
            return false;
        }

        if ($needMd) {
            $mdFile = $columnDir.DIRECTORY_SEPARATOR.Filenamify::filenamify($article->title).'.md';
            if (! file_exists($mdFile)) {
                return false;
            }
        }

        if ($needAudio) {
            $audioFile = $columnDir.DIRECTORY_SEPARATOR.Filenamify::filenamify($article->title).'.mp3';
            if (! file_exists($audioFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Download a text article in all requested formats.
     *
     * Matches Go's downloadTextArticle.
     * Fetches article detail via V1 API, then downloads in each requested format.
     * Also handles inline video and embedded MP4 downloads.
     *
     * @param  Article  $article  Article to download
     * @param  string  $columnDir  Output directory
     * @param  bool  $overwrite  Whether to overwrite existing files
     */
    private function downloadTextArticle(Article $article, string $columnDir, bool $overwrite): void
    {
        $needPdf = OutputType::PDF->isSetIn($this->config->columnOutputType);
        $needMd = OutputType::Markdown->isSetIn($this->config->columnOutputType);
        $needAudio = OutputType::Audio->isSetIn($this->config->columnOutputType);

        if ($this->geektimeApi === null) {
            Log::error('GeektimeApi is required for text article download');

            return;
        }

        // Fetch full article detail
        $articleInfo = $this->geektimeApi->v1ArticleInfo($article->aid);
        $articleContent = (string) ($articleInfo['article_content'] ?? '');
        $audioDownloadUrl = (string) ($articleInfo['audio_download_url'] ?? '');
        $inlineVideoSubtitles = (array) ($articleInfo['inline_video_subtitles'] ?? []);

        // Check for embedded video in article content (see Go issue #104)
        $embeddedVideo = $this->getVideoUrlFromArticleContent($articleContent);
        if ($embeddedVideo !== null) {
            try {
                $this->videoDownloader->downloadMp4($article->title, $columnDir, [$embeddedVideo], $overwrite);
            } catch (\Throwable $e) {
                Log::error('Failed to download embedded video from article content', [
                    'articleID' => $article->aid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Download inline video subtitles (MP4s embedded via inline_video_subtitles)
        if (! empty($inlineVideoSubtitles)) {
            $videoUrls = [];
            foreach ($inlineVideoSubtitles as $subtitle) {
                $videoUrl = (string) ($subtitle['video_url'] ?? '');
                if ($videoUrl !== '') {
                    $videoUrls[] = $videoUrl;
                }
            }
            if (! empty($videoUrls)) {
                try {
                    $this->videoDownloader->downloadMp4($article->title, $columnDir, $videoUrls, $overwrite);
                } catch (\Throwable $e) {
                    Log::error('Failed to download inline video subtitles', [
                        'articleID' => $article->aid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Download PDF
        if ($needPdf) {
            try {
                $this->pdfDownloader->download(
                    $article,
                    $columnDir,
                    $this->config->gcid,
                    $this->config->gcess,
                    $this->config,
                    $overwrite,
                );
            } catch (\Throwable $e) {
                Log::error('Failed to download article as PDF', [
                    'articleID' => $article->aid,
                    'title' => $article->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Download Markdown
        if ($needMd) {
            try {
                $this->markdownDownloader->download(
                    $articleContent,
                    $article->title,
                    $columnDir,
                    $article->aid,
                    $overwrite,
                );
            } catch (\Throwable $e) {
                Log::error('Failed to download article as Markdown', [
                    'articleID' => $article->aid,
                    'title' => $article->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Download Audio
        if ($needAudio) {
            try {
                $this->audioDownloader->download(
                    $audioDownloadUrl,
                    $columnDir,
                    $article->title,
                    $overwrite,
                );
            } catch (\Throwable $e) {
                Log::error('Failed to download article audio', [
                    'articleID' => $article->aid,
                    'title' => $article->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Download a video article.
     *
     * Matches Go's downloadVideoArticle.
     * Delegates to the appropriate API-specific video downloader based on
     * whether this is a university, enterprise, or regular course.
     *
     * @param  Course  $course  The course
     * @param  Article  $article  The article to download
     * @param  string  $columnDir  Base output directory
     * @param  bool  $isUniversity  Whether this is a university course
     */
    private function downloadVideoArticle(
        Course $course,
        Article $article,
        string $columnDir,
        bool $isUniversity,
    ): void {
        // Handle section subdirectories for enterprise courses
        $dir = $columnDir;
        if ($article->sectionTitle !== '') {
            $dir = $this->mkDownloadProjectSectionDir($columnDir, $article->sectionTitle);
        }

        if ($isUniversity && $this->universityApi !== null) {
            $this->videoDownloader->downloadUniversityVideo(
                $this->universityApi,
                $article->aid,
                $course,
                $dir,
                $this->config->quality,
                $this->concurrency,
            );
        } elseif ($this->config->isEnterprise && $this->enterpriseApi !== null) {
            $this->videoDownloader->downloadEnterpriseArticleVideo(
                $this->enterpriseApi,
                $article->aid,
                $dir,
                $this->config->quality,
                $this->concurrency,
            );
        } elseif ($this->geektimeApi !== null) {
            // Normal video course, sourceType=1
            $this->videoDownloader->downloadArticleVideo(
                $this->geektimeApi,
                $article->aid,
                1, // sourceType for normal video courses
                $dir,
                $this->config->quality,
                $this->concurrency,
            );
        } else {
            Log::error('No API client available for video download', [
                'articleID' => $article->aid,
            ]);
        }
    }

    /**
     * Extract video URL from article HTML content.
     *
     * Sometimes video is embedded directly in article content (see Go issue #104).
     * Looks for <video> tags containing <source> elements with .mp4 URLs.
     *
     * Matches Go's getVideoURLFromArticleContent function.
     *
     * @param  string  $content  Article HTML content
     * @return string|null MP4 URL if found, null otherwise
     */
    private function getVideoUrlFromArticleContent(string $content): ?string
    {
        if (! str_contains($content, '<video') || ! str_contains($content, '<source')) {
            return null;
        }

        // Use DOMDocument to parse HTML and find video source URLs
        $dom = new \DOMDocument();
        // Suppress warnings from malformed HTML
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $hasVideo = false;
        $videoUrl = null;

        // Check for video elements
        $videos = $dom->getElementsByTagName('video');
        if ($videos->length > 0) {
            $hasVideo = true;
        }

        if ($hasVideo) {
            $sources = $dom->getElementsByTagName('source');
            foreach ($sources as $source) {
                $src = $source->getAttribute('src');
                if ($src !== '' && str_ends_with($src, '.mp4')) {
                    $videoUrl = $src;
                    break;
                }
            }
        }

        return $videoUrl;
    }

    /**
     * Create the download directory for a course.
     *
     * Matches Go's mkDownloadColumnDir.
     *
     * @param  string  $columnName  Course title
     * @return string Full path to the created directory
     */
    private function mkDownloadColumnDir(string $columnName): string
    {
        $path = $this->config->downloadFolder.DIRECTORY_SEPARATOR.Filenamify::filenamify($columnName);

        if (! is_dir($path) && ! mkdir($path, 0o777, true) && ! is_dir($path)) {
            throw new \RuntimeException('Failed to create download directory: '.$path);
        }

        return $path;
    }

    /**
     * Create a subdirectory for a project section (used by enterprise courses).
     *
     * Matches Go's mkDownloadProjectSectionDir.
     *
     * @param  string  $projectDir  Parent project directory
     * @param  string  $sectionName  Section name
     * @return string Full path to the created section directory
     */
    private function mkDownloadProjectSectionDir(string $projectDir, string $sectionName): string
    {
        $path = $projectDir.DIRECTORY_SEPARATOR.Filenamify::filenamify($sectionName);

        if (! is_dir($path) && ! mkdir($path, 0o777, true) && ! is_dir($path)) {
            throw new \RuntimeException('Failed to create section directory: '.$path);
        }

        return $path;
    }

    /**
     * Wait a random amount of time between downloads to avoid rate limiting.
     *
     * Matches Go's waitRandomTime: interval seconds + random 0-2000ms jitter.
     */
    private function waitRandomTime(): void
    {
        $intervalMs = $this->config->interval * 1000;
        $jitterMs = random_int(0, 2000);
        $totalMs = $intervalMs + $jitterMs;

        if ($totalMs > 0) {
            usleep($totalMs * 1000);
        }
    }

    /**
     * Show download progress.
     */
    private function showProgress(int $total, int $downloaded): void
    {
        $current = min($downloaded, $total);
        Log::info(sprintf('Download progress: %d/%d', $current, $total));
    }
}
