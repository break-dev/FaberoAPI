<?php

namespace App\Shared\IA\Exceptions;

use RuntimeException;

class AIProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}