<?php

declare(strict_types=1);

namespace App\Support;

class FileHelper
{
    /**
     * Check if a file exists at the given path.
     *
     * Ported from Go: internal/pkg/files/files.go
     */
    public static function checkFileExists(string $filePath): bool
    {
        return file_exists($filePath);
    }
}
