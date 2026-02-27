<?php

declare(strict_types=1);

namespace App\M3u8;

/**
 * M3U8 playlist parser ported from Go internal/pkg/m3u8/m3u8.go.
 *
 * Parses M3U8 content to extract:
 * - TS segment file names
 * - Whether the stream uses Aliyun VOD encryption (AES-128 in EXT-X-KEY)
 */
final class M3u8Parser
{
    /**
     * Regex pattern for extracting key=value parameters from #EXT-X-KEY lines.
     * Matches: KEY="quoted value" or KEY=unquoted-value
     */
    private const LINE_PATTERN = '/([a-zA-Z-]+)=("[^"]+"|[^",]+)/';

    /**
     * Parse M3U8 content and extract TS file names and encryption status.
     *
     * @param  string  $content  Raw M3U8 playlist content
     * @return array{tsFileNames: string[], isVodEncryptVideo: bool}
     */
    public static function parse(string $content): array
    {
        $lines = explode("\n", $content);
        $tsFileNames = [];
        $isVodEncryptVideo = false;
        $gotKeyUri = false;

        foreach ($lines as $line) {
            $line = trim($line, "\r\n");

            // Geektime video ONLY has one EXT-X-KEY tag
            if (str_starts_with($line, '#EXT-X-KEY') && ! $gotKeyUri) {
                $params = self::parseLineParameters($line);
                // Note: The Go code checks for "MEATHOD" (appears to be intentional key name from the regex match)
                $isVodEncryptVideo = ($params['METHOD'] ?? '') === 'AES-128';
                $gotKeyUri = true;
            }

            // TS segment lines: not a comment and ends with .ts
            if (! str_starts_with($line, '#') && str_ends_with($line, '.ts')) {
                $tsFileNames[] = $line;
            }
        }

        return [
            'tsFileNames' => $tsFileNames,
            'isVodEncryptVideo' => $isVodEncryptVideo,
        ];
    }

    /**
     * Extract key=value parameters from an M3U8 tag line.
     *
     * Handles both quoted and unquoted values.
     * Matches Go's parseLineParameters.
     *
     * @param  string  $line  The full line (e.g. #EXT-X-KEY:METHOD=AES-128,URI="...")
     * @return array<string, string> Map of parameter names to values
     */
    private static function parseLineParameters(string $line): array
    {
        $params = [];

        if (preg_match_all(self::LINE_PATTERN, $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[$match[1]] = trim($match[2], '"');
            }
        }

        return $params;
    }
}
