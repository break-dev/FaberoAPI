<?php

namespace App\Shared\IA\DTO;

final class AIChatResponse
{
    /**
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly mixed $structured = null,
        public readonly ?array $usage = null,
        public readonly ?array $raw = null,
    ) {
    }
}