<?php

declare(strict_types=1);

namespace App\Support;

class Filenamify
{
    private const MAX_FILE_NAME_LENGTH = 100;

    private const REPLACEMENT = '-';

    /**
     * Convert a string to a valid safe filename.
     *
     * Ported from Go: internal/pkg/filenamify/filenamify.go
     */
    public static function filenamify(string $str): string
    {
        // Remove whitespace (equivalent to strings.Join(strings.Fields(str), ""))
        $str = preg_replace('/\s+/', '', $str);

        $reControlChars = '/[\x00-\x1f\x80-\x9f]/';
        $reRelativePath = '/^\.+/';
        $forbiddenWindowsChars = '/[<>:"\/\\\\|?*\x00-\x1f]/';
        $reservedWindowsNames = '/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/i';

        // Replace forbidden Windows characters
        $str = preg_replace($forbiddenWindowsChars, self::REPLACEMENT, $str);

        // Replace control characters
        $str = preg_replace($reControlChars, self::REPLACEMENT, $str);

        // Replace relative path dots at the start
        $str = preg_replace($reRelativePath, self::REPLACEMENT, $str);

        // Trim repeated replacement characters
        if (mb_strlen(self::REPLACEMENT) > 0) {
            $str = self::trimRepeated($str, self::REPLACEMENT);

            if (mb_strlen($str) > 1) {
                $str = self::stripOuter($str, self::REPLACEMENT);
            }
        }

        // Handle reserved Windows names
        if (preg_match($reservedWindowsNames, $str)) {
            $str .= self::REPLACEMENT;
        }

        // Limit length (using mb_substr to handle multibyte characters like Chinese)
        $str = mb_substr($str, 0, self::MAX_FILE_NAME_LENGTH);

        return $str;
    }

    /**
     * Escape special regex characters in a string.
     */
    private static function escapeStringRegexp(string $str): string
    {
        return preg_replace_callback('/[|\\\\{}()\[\]^$+*?.\-]/', function (array $matches): string {
            return '\\' . $matches[0];
        }, $str);
    }

    /**
     * Replace consecutive occurrences of replacement with a single one.
     */
    private static function trimRepeated(string $str, string $replacement): string
    {
        $escaped = self::escapeStringRegexp($replacement);
        $pattern = '/(?:' . $escaped . '){2,}/';

        return preg_replace($pattern, $replacement, $str);
    }

    /**
     * Strip the given substring from the beginning and end of the input string.
     */
    private static function stripOuter(string $input, string $substring): string
    {
        $escaped = self::escapeStringRegexp($substring);
        $pattern = '/^' . $escaped . '|' . $escaped . '$/';

        return preg_replace($pattern, '', $input);
    }
}
