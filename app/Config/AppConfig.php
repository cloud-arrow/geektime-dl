<?php

declare(strict_types=1);

namespace App\Config;

use App\Enums\OutputType;
use App\Enums\Quality;
use InvalidArgumentException;

class AppConfig
{
    private const CONFIG_DIR = '.geektime';

    private const CONFIG_FILE = 'config.json';

    public function __construct(
        public string $gcid = '',
        public string $gcess = '',
        public string $downloadFolder = '',
        public string $quality = 'sd',
        public int $downloadComments = 0,
        public int $columnOutputType = 1,
        public int $printPdfWaitSeconds = 0,
        public int $printPdfTimeoutSeconds = 30,
        public int $interval = 0,
        public bool $isEnterprise = false,
        public string $logLevel = 'info',
    ) {}

    /**
     * Parse a cookie string and extract GCID and GCESS values.
     *
     * Accepts cookie strings in the format: "GCID=value1; GCESS=value2"
     */
    public function readCookiesFromInput(string $cookieString): void
    {
        $cookies = array_map('trim', explode(';', $cookieString));

        foreach ($cookies as $cookie) {
            $parts = explode('=', $cookie, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            match ($name) {
                'GCID' => $this->gcid = $value,
                'GCESS' => $this->gcess = $value,
                default => null,
            };
        }
    }

    /**
     * Validate the configuration and throw on invalid values.
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        $this->validateCookies();
        $this->validateComments();
        $this->validateQuality();
        $this->validateColumnOutputType();
        $this->validateLogLevel();
        $this->validateTiming();
    }

    /**
     * Load configuration from the default JSON config file.
     *
     * @return static|null Returns null if the config file does not exist.
     */
    public static function loadFromFile(): ?static
    {
        $path = static::configFilePath();

        if (! file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        return new static(
            gcid: (string) ($data['gcid'] ?? ''),
            gcess: (string) ($data['gcess'] ?? ''),
            downloadFolder: (string) ($data['download_folder'] ?? ''),
            quality: (string) ($data['quality'] ?? 'sd'),
            downloadComments: (int) ($data['download_comments'] ?? 0),
            columnOutputType: (int) ($data['column_output_type'] ?? 1),
            printPdfWaitSeconds: (int) ($data['print_pdf_wait_seconds'] ?? 0),
            printPdfTimeoutSeconds: (int) ($data['print_pdf_timeout_seconds'] ?? 30),
            interval: (int) ($data['interval'] ?? 0),
            isEnterprise: (bool) ($data['is_enterprise'] ?? false),
            logLevel: (string) ($data['log_level'] ?? 'info'),
        );
    }

    /**
     * Save the current configuration to the default JSON config file.
     *
     * @throws \RuntimeException
     */
    public function saveToFile(): void
    {
        $path = static::configFilePath();
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create config directory: {$dir}");
        }

        $data = [
            'gcid' => $this->gcid,
            'gcess' => $this->gcess,
            'download_folder' => $this->downloadFolder,
            'quality' => $this->quality,
            'download_comments' => $this->downloadComments,
            'column_output_type' => $this->columnOutputType,
            'print_pdf_wait_seconds' => $this->printPdfWaitSeconds,
            'print_pdf_timeout_seconds' => $this->printPdfTimeoutSeconds,
            'interval' => $this->interval,
            'is_enterprise' => $this->isEnterprise,
            'log_level' => $this->logLevel,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Failed to write config file: {$path}");
        }
    }

    /**
     * Get the full path to the config file.
     */
    public static function configFilePath(): string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');

        if ($home === false || $home === '') {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
        }

        return rtrim($home, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.self::CONFIG_DIR
            .DIRECTORY_SEPARATOR.self::CONFIG_FILE;
    }

    private function validateCookies(): void
    {
        if ($this->gcid === '' || $this->gcess === '') {
            throw new InvalidArgumentException(
                "Arguments 'gcid' and 'gcess' are required and cannot be empty"
            );
        }
    }

    private function validateComments(): void
    {
        if (! in_array($this->downloadComments, [0, 1, 2], true)) {
            throw new InvalidArgumentException(
                "Argument 'comments' is not valid, must be one of 0, 1, 2"
            );
        }
    }

    private function validateQuality(): void
    {
        if (Quality::tryFrom($this->quality) === null) {
            throw new InvalidArgumentException(
                "Argument 'quality' is not valid, must be one of ld, sd, hd"
            );
        }
    }

    private function validateColumnOutputType(): void
    {
        if (! OutputType::isValidBitmask($this->columnOutputType)) {
            throw new InvalidArgumentException(
                "Argument 'output' is not valid, must be between 1 and 7"
            );
        }
    }

    private function validateLogLevel(): void
    {
        $validLevels = ['debug', 'info', 'warn', 'error', 'none'];

        if (! in_array($this->logLevel, $validLevels, true)) {
            throw new InvalidArgumentException(
                "Argument 'log-level' is not valid, must be one of debug, info, warn, error, none"
            );
        }
    }

    private function validateTiming(): void
    {
        if ($this->interval < 0 || $this->interval > 10) {
            throw new InvalidArgumentException(
                "Argument 'interval' must be between 0 and 10"
            );
        }

        if ($this->printPdfWaitSeconds < 0 || $this->printPdfWaitSeconds > 60) {
            throw new InvalidArgumentException(
                "Argument 'print-pdf-wait' must be between 0 and 60"
            );
        }

        if ($this->printPdfTimeoutSeconds <= 0 || $this->printPdfTimeoutSeconds > 120) {
            throw new InvalidArgumentException(
                "Argument 'print-pdf-timeout' must be between 1 and 120"
            );
        }
    }
}
