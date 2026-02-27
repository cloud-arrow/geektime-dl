<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Video quality levels for downloading.
 *
 * Maps to the Go quality constants: "ld", "sd", "hd".
 */
enum Quality: string
{
    case LD = 'ld';
    case SD = 'sd';
    case HD = 'hd';

    public function label(): string
    {
        return match ($this) {
            self::LD => 'Low Definition',
            self::SD => 'Standard Definition',
            self::HD => 'High Definition',
        };
    }
}
