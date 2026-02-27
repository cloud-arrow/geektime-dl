<?php

declare(strict_types=1);

namespace App\Downloader;

use App\Config\AppConfig;
use App\Geektime\Dto\Article;
use App\Geektime\Exceptions\RateLimitException;
use App\Support\FileHelper;
use App\Support\Filenamify;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * Generates PDF files from article pages using Browsershot (headless Chrome).
 *
 * Ported from Go internal/pdf/pdf.go.
 *
 * Uses Browsershot (Spatie) as a PHP equivalent of Go's chromedp:
 * - Navigates to the article URL on time.geekbang.org
 * - Sets authentication cookies
 * - Emulates iPad Pro 11 viewport (834x1194)
 * - Removes UI clutter via JavaScript
 * - Handles comment loading based on config
 * - Generates PDF with 0.4-inch margins
 */
final class PdfDownloader
{
    private const PDF_EXTENSION = '.pdf';

    private const DEFAULT_BASE_URL = 'https://time.geekbang.org';

    private const COOKIE_DOMAIN = '.geekbang.org';

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36';

    /**
     * Download comments mode: hide comments entirely.
     */
    public const DOWNLOAD_COMMENTS_NONE = 0;

    /**
     * Download comments mode: show first page only (default).
     */
    public const DOWNLOAD_COMMENTS_FIRST_PAGE = 1;

    /**
     * Download comments mode: load all comments via scroll simulation.
     */
    public const DOWNLOAD_COMMENTS_ALL = 2;

    /**
     * JavaScript to hide redundant UI elements.
     *
     * Matches Go's hideRedundantElements function.
     */
    private const JS_HIDE_REDUNDANT_ELEMENTS = <<<'JS'
        var headMain = document.getElementsByClassName('main')[0];
        if(headMain){
            headMain.style.display="none";
        }
        var bottomWrapper = document.getElementsByClassName('sub-bottom-wrapper')[0];
        if(bottomWrapper){
            bottomWrapper.style.display="none";
        }
        var openAppdiv = document.getElementsByClassName('openApp')[0];
        if(openAppdiv){
            openAppdiv.parentNode.parentNode.parentNode.style.display="none";
        }
        var audioPlayer = document.querySelector('div[class^="ColumnArticleMiniAudioPlayer"]');
        if(audioPlayer){
            audioPlayer.style.display="none"
        }
        var audioFloatBar = document.querySelector('div[class*="audio-float-bar"]');
        if(audioFloatBar){
            audioFloatBar.style.display="none"
        }
        var leadsWrapper = document.querySelector('div[class^="leads-wrapper"]');
        if(leadsWrapper){
            leadsWrapper.style.display="none";
        }
        var unPreviewImage = document.querySelector('img[alt="unpreview"]');
        if(unPreviewImage){
            unPreviewImage.style.display="none"
        }
        var gotoColumn = document.querySelector('div[class^="Index_articleColumn"]');
        if(gotoColumn){
            gotoColumn.style.display="none"
        }
        var favBtn = document.querySelector('div[class*="Index_favBtn"]');
        if(favBtn){
            favBtn.style.display="none"
        }
        var likeModule = document.querySelector('div[class^="ArticleLikeModuleMobile"]');
        if(likeModule){
            likeModule.style.display="none"
        }
        var switchBtns = document.querySelector('div[class^="Index_switchBtns"]');
        if(switchBtns){
            switchBtns.style.display="none"
        }
        var writeComment = document.querySelector('div[class*="Index_writeComment"]');
        if(writeComment){
            writeComment.style.display="none"
        }
        var moreBtns = document.querySelectorAll('div[class^=CommentItem_more]');
        for (let btn of moreBtns) {
            btn.click();
        }
    JS;

    /**
     * JavaScript to hide the comments block entirely.
     *
     * Matches Go's hideCommentsBlock function.
     */
    private const JS_HIDE_COMMENTS = <<<'JS'
        var comments = document.querySelector('div[class^="Index_articleComments"]');
        if(comments){
            comments.style.display="none"
        }
    JS;

    /**
     * JavaScript to scroll down and trigger loading of all comments.
     *
     * This is a simplified version of Go's touchScrollAction that uses
     * repeated scrolling to load all paginated comments.
     */
    private const JS_LOAD_ALL_COMMENTS = <<<'JS'
        async function loadAllComments() {
            const maxScrollAttempts = 100;
            let attempts = 0;
            while (attempts < maxScrollAttempts) {
                window.scrollTo(0, document.body.scrollHeight);
                await new Promise(r => setTimeout(r, 500));
                attempts++;
                // Check if there's a "load more" element or if we've reached the end
                var loadMore = document.querySelector('div[class*="loadMore"]');
                if (!loadMore) break;
            }
        }
        loadAllComments();
    JS;

    /**
     * Print an article page to PDF.
     *
     * Matches Go's PrintArticlePageToPDF function.
     *
     * @param  Article  $article  Article to download
     * @param  string  $dir  Output directory
     * @param  string  $gcid  GCID cookie value
     * @param  string  $gcess  GCESS cookie value
     * @param  AppConfig  $config  Application configuration
     * @param  bool  $overwrite  Whether to overwrite existing files
     *
     * @throws RateLimitException If GeekTime rate limit is hit (HTTP 451)
     */
    public function download(
        Article $article,
        string $dir,
        string $gcid,
        string $gcess,
        AppConfig $config,
        bool $overwrite = false,
    ): void {
        $pdfFileName = $dir.DIRECTORY_SEPARATOR.Filenamify::filenamify($article->title).self::PDF_EXTENSION;

        if (! $overwrite && FileHelper::checkFileExists($pdfFileName)) {
            Log::info('PDF file already exists, skipping', ['file' => $pdfFileName]);

            return;
        }

        $url = self::DEFAULT_BASE_URL.'/column/article/'.$article->aid;

        Log::info('Begin download article pdf', ['articleID' => $article->aid, 'pdfFileName' => $pdfFileName]);

        // Build JavaScript to execute based on comment download mode
        $jsToExecute = $this->buildJavaScript($config->downloadComments);

        try {
            $browsershot = Browsershot::url($url)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                ->setOption('cookies', [
                    [
                        'name' => 'GCID',
                        'value' => $gcid,
                        'domain' => self::COOKIE_DOMAIN,
                        'path' => '/',
                        'httpOnly' => true,
                        'secure' => true,
                    ],
                    [
                        'name' => 'GCESS',
                        'value' => $gcess,
                        'domain' => self::COOKIE_DOMAIN,
                        'path' => '/',
                        'httpOnly' => true,
                        'secure' => true,
                    ],
                ])
                ->windowSize(834, 1194)
                ->userAgent(self::DEFAULT_USER_AGENT)
                ->waitUntilNetworkIdle()
                ->timeout($config->printPdfTimeoutSeconds);

            // Wait for content to load
            if ($config->printPdfWaitSeconds > 0) {
                $browsershot->setDelay($config->printPdfWaitSeconds * 1000);
            }

            // Execute JavaScript to clean up page
            $browsershot->evaluate($jsToExecute);

            // Set PDF margins (0.4 inches on all sides, converted to mm: 0.4 * 25.4 = 10.16)
            $marginMm = 10.16;
            $browsershot->margins($marginMm, $marginMm, $marginMm, $marginMm);

            // Ensure output directory exists
            $outputDir = dirname($pdfFileName);
            if (! is_dir($outputDir) && ! mkdir($outputDir, 0o777, true) && ! is_dir($outputDir)) {
                Log::error('Failed to create output directory for PDF', ['dir' => $outputDir]);

                return;
            }

            $browsershot->savePdf($pdfFileName);

            Log::info('Finish download article pdf', ['pdfFileName' => $pdfFileName]);
        } catch (\Throwable $e) {
            // Check for rate limit in error message
            if (str_contains($e->getMessage(), '451')) {
                Log::warning('Hit GeekTime rate limit when downloading article pdf', [
                    'articleID' => $article->aid,
                    'pdfFileName' => $pdfFileName,
                ]);

                throw new RateLimitException(previous: $e);
            }

            Log::error('Failed to download article pdf', [
                'articleID' => $article->aid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if a PDF file already exists for the given article.
     *
     * @param  string  $articleTitle  Article title
     * @param  string  $columnDir  Course output directory
     * @return bool True if file already exists
     */
    public function pdfFileExists(string $articleTitle, string $columnDir): bool
    {
        $pdfFileName = $columnDir.DIRECTORY_SEPARATOR.Filenamify::filenamify($articleTitle).self::PDF_EXTENSION;

        return FileHelper::checkFileExists($pdfFileName);
    }

    /**
     * Build the combined JavaScript to execute on the page.
     *
     * Combines comment handling and redundant element hiding scripts.
     *
     * @param  int  $downloadComments  Comment download mode (0=none, 1=first page, 2=all)
     * @return string Combined JavaScript
     */
    private function buildJavaScript(int $downloadComments): string
    {
        $js = '';

        // Handle comments based on mode
        switch ($downloadComments) {
            case self::DOWNLOAD_COMMENTS_ALL:
                $js .= self::JS_LOAD_ALL_COMMENTS."\n";
                break;
            case self::DOWNLOAD_COMMENTS_NONE:
                $js .= self::JS_HIDE_COMMENTS."\n";
                break;
            case self::DOWNLOAD_COMMENTS_FIRST_PAGE:
            default:
                // Show first page only - no extra JS needed
                break;
        }

        // Always hide redundant elements
        $js .= self::JS_HIDE_REDUNDANT_ELEMENTS;

        return $js;
    }
}
