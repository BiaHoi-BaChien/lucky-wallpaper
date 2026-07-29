<?php

namespace App\Exceptions;

use RuntimeException;

class ExternalApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable = false,
        string $message = 'External API request failed.',
    ) {
        parent::__construct($message);
    }
}
