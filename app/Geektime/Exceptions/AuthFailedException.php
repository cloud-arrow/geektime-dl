<?php

declare(strict_types=1);

namespace App\Geektime\Exceptions;

class AuthFailedException extends ApiException
{
    public function __construct(
        string $message = 'Authentication failed. Your account may have logged in on another device or the session has expired. Please try logging in again.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, code: $code, previous: $previous);
    }
}
