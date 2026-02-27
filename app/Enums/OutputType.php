<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Output format bitmask for column/text course downloads.
 *
 * Reference: internal/course/downloader.go
 *   outputPDF   = 1 << 0  // 1
 *   outputMD    = 1 << 1  // 2
 *   outputAudio = 1 << 2  // 4
 *
 * Values can be combined as a bitmask (1-7).
 */
enum OutputType: int
{
    case PDF = 1;
    case Markdown = 2;
    case Audio = 4;

    /**
     * Check whether this output type is enabled within the given bitmask.
     */
    public function enabledIn(int $bitmask): bool
    {
        return ($bitmask & $this->value) !== 0;
    }

    /**
     * Alias for enabledIn — check whether this output type is set in the given bitmask.
     */
    public function isSetIn(int $bitmask): bool
    {
        return $this->enabledIn($bitmask);
    }

    /**
     * Check whether a given bitmask value is within the valid range (1-7).
     */
    public static function isValidBitmask(int $bitmask): bool
    {
        return $bitmask > 0 && $bitmask < 8;
    }

    /**
     * Return all output types that are enabled in the given bitmask.
     *
     * @return list<self>
     */
    public static function fromBitmask(int $bitmask): array
    {
        $types = [];

        foreach (self::cases() as $case) {
            if ($case->enabledIn($bitmask)) {
                $types[] = $case;
            }
        }

        return $types;
    }
}
