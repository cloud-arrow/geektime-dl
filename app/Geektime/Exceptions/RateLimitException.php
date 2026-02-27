<?php

declare(strict_types=1);

namespace App\Geektime\Exceptions;

class RateLimitException extends ApiException
{
    public function __construct(
        string $message = 'Rate limit triggered. You can try re-logging in or obtaining a new cookie, or wait and retry to generate the remaining articles.',
        int $code = 451,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, code: $code, previous: $previous);
    }
}
