<?php

declare(strict_types=1);

namespace App\Geektime\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message = 'GeekTime API request failed',
        public readonly string $path = '',
        public readonly string $responseBody = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromResponse(string $path, string $responseBody): self
    {
        return new self(
            message: sprintf('GeekTime API request to %s failed, response: %s', $path, $responseBody),
            path: $path,
            responseBody: $responseBody,
        );
    }
}
