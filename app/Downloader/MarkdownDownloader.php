<?php

declare(strict_types=1);

namespace App\Downloader;

use App\Http\FileDownloader;
use App\Support\FileHelper;
use App\Support\Filenamify;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Downloads article content as Markdown with local images.
 *
 * Ported from Go internal/markdown/markdown.go.
 *
 * Converts article HTML to Markdown using league/html-to-markdown,
 * downloads all referenced images locally, and rewrites image URLs
 * to relative paths.
 */
final class MarkdownDownloader
{
    private const MD_EXTENSION = '.md';

    private const DEFAULT_BASE_URL = 'https://time.geekbang.org';

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36';

    /**
     * Valid image file extensions (lowercase).
     */
    private const VALID_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];

    /**
     * Regex pattern to find Markdown image references.
     * Matches: ![alt text](url)
     */
    private const IMAGE_REGEX = '/!\[.*?\]\((.*?)\)/';

    private static ?HtmlConverter $converter = null;

    public function __construct(
        private readonly FileDownloader $fileDownloader,
    ) {}

    /**
     * Download article as a Markdown file with locally-saved images.
     *
     * Matches Go's markdown.Download function:
     * 1. Convert HTML content to Markdown
     * 2. Find all image URLs in the Markdown
     * 3. Download each image to {dir}/images/{aid}/
     * 4. Rewrite image URLs to relative paths
     * 5. Write the final Markdown file with title as H1 header
     *
     * @param  string  $html  Article HTML content
     * @param  string  $title  Article title
     * @param  string  $dir  Output directory path
     * @param  int  $aid  Article ID (used for image subfolder)
     * @param  bool  $overwrite  Whether to overwrite existing files
     */
    public function download(string $html, string $title, string $dir, int $aid, bool $overwrite = false): void
    {
        Log::info('Begin download article markdown', ['articleID' => $aid, 'title' => $title]);

        $markdownFileName = $dir.DIRECTORY_SEPARATOR.Filenamify::filenamify($title).self::MD_EXTENSION;

        if (! $overwrite && FileHelper::checkFileExists($markdownFileName)) {
            Log::info('Markdown file already exists, skipping', ['file' => $markdownFileName]);

            return;
        }

        // Step 1: Convert HTML to Markdown
        $markdown = $this->getConverter()->convert($html);

        // Step 2: Find all image URLs
        $imageUrls = $this->findAllImages($markdown);

        // Step 3: Create images directory and download images
        $imagesFolder = $dir.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.(string) $aid;

        if (! is_dir($imagesFolder)) {
            if (! mkdir($imagesFolder, 0o777, true) && ! is_dir($imagesFolder)) {
                Log::error('Failed to create images directory', ['dir' => $imagesFolder]);

                return;
            }
        }

        // Step 4: Download images and rewrite URLs in markdown
        $markdown = $this->downloadAndRewriteImages($imageUrls, $dir, $imagesFolder, $markdown);

        // Step 5: Write the Markdown file with title as H1 header
        $content = '# '.$title."\n".$markdown;

        $result = file_put_contents($markdownFileName, $content);
        if ($result === false) {
            Log::error('Failed to write markdown file', ['file' => $markdownFileName]);

            return;
        }

        Log::info('Finish download article markdown', ['articleID' => $aid, 'title' => $title]);
    }

    /**
     * Find all image URLs in the Markdown content.
     *
     * Validates that each URL has a recognized image file extension.
     * Matches Go's findAllImages function.
     *
     * @return string[] List of image URLs
     */
    private function findAllImages(string $markdown): array
    {
        $images = [];

        if (preg_match_all(self::IMAGE_REGEX, $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (isset($match[1])) {
                    $url = $match[1];
                    if ($this->isImageUrl($url)) {
                        $images[] = $url;
                    }
                    // Broken image URLs are silently ignored, matching Go behavior
                }
            }
        }

        return $images;
    }

    /**
     * Check if a URL points to a valid image file.
     *
     * Parses the URL, extracts the file extension from the path component,
     * and checks against known image extensions.
     * Matches Go's isImageURL function.
     */
    private function isImageUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['path'])) {
            return false;
        }

        $ext = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));

        return in_array($ext, self::VALID_IMAGE_EXTENSIONS, true);
    }

    /**
     * Download all images and rewrite their URLs in the Markdown content.
     *
     * For each image URL:
     * - Extracts the filename from the URL (removing query parameters)
     * - Downloads to the images folder
     * - Replaces the original URL with a relative path
     *
     * Matches Go's writeImageFile function.
     *
     * @param  string[]  $imageUrls  List of image URLs to download
     * @param  string  $dir  Base output directory (for computing relative paths)
     * @param  string  $imagesFolder  Directory to save images to
     * @param  string  $markdown  Markdown content to rewrite
     * @return string Markdown content with rewritten image URLs
     */
    private function downloadAndRewriteImages(array $imageUrls, string $dir, string $imagesFolder, string $markdown): string
    {
        $headers = [
            'Origin' => self::DEFAULT_BASE_URL,
            'User-Agent' => self::DEFAULT_USER_AGENT,
        ];

        foreach ($imageUrls as $imageUrl) {
            try {
                // Extract filename from URL, removing query parameters
                $segments = explode('/', $imageUrl);
                $filename = end($segments);

                // Remove query string from filename
                $queryPos = strpos($filename, '?');
                if ($queryPos !== false && $queryPos > 0) {
                    $filename = substr($filename, 0, $queryPos);
                }

                $imageLocalFullPath = $imagesFolder.DIRECTORY_SEPARATOR.$filename;

                $this->fileDownloader->download($imageLocalFullPath, $imageUrl, $headers, 1);

                // Compute relative path from dir to the downloaded image
                // This matches Go's filepath.Rel(dir, imageLocalFullPath)
                $relativePath = $this->getRelativePath($dir, $imageLocalFullPath);

                // Replace URL in markdown with relative path (using forward slashes)
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                $markdown = str_replace($imageUrl, $relativePath, $markdown);
            } catch (\Throwable $e) {
                Log::error('Failed to download image', [
                    'url' => $imageUrl,
                    'error' => $e->getMessage(),
                ]);

                // Re-throw to match Go behavior which returns error on image download failure
                throw $e;
            }
        }

        return $markdown;
    }

    /**
     * Compute the relative path from a base directory to a target file.
     *
     * Equivalent to Go's filepath.Rel(basePath, targetPath).
     */
    private function getRelativePath(string $basePath, string $targetPath): string
    {
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $targetPath = rtrim($targetPath, DIRECTORY_SEPARATOR);

        // If the target starts with the base, just strip it
        if (str_starts_with($targetPath, $basePath)) {
            return substr($targetPath, strlen($basePath));
        }

        // Fallback: compute a simple relative path
        $baseParts = explode(DIRECTORY_SEPARATOR, rtrim($basePath, DIRECTORY_SEPARATOR));
        $targetParts = explode(DIRECTORY_SEPARATOR, $targetPath);

        // Find common prefix length
        $commonLength = 0;
        $minLength = min(count($baseParts), count($targetParts));
        for ($i = 0; $i < $minLength; $i++) {
            if ($baseParts[$i] !== $targetParts[$i]) {
                break;
            }
            $commonLength++;
        }

        // Build relative path
        $upCount = count($baseParts) - $commonLength;
        $upParts = array_fill(0, $upCount, '..');
        $downParts = array_slice($targetParts, $commonLength);

        return implode(DIRECTORY_SEPARATOR, array_merge($upParts, $downParts));
    }

    /**
     * Get or create the HTML-to-Markdown converter singleton.
     *
     * Matches Go's getDefaultConverter() lazy initialization.
     */
    private function getConverter(): HtmlConverter
    {
        if (self::$converter === null) {
            self::$converter = new HtmlConverter([
                'strip_tags' => true,
            ]);
        }

        return self::$converter;
    }
}
