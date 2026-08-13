<?php

namespace App\Shared\IA\DTO;

final class AIAttachment
{
    public function __construct(
        public readonly string $mime,
        public readonly ?string $base64 = null,
        public readonly ?string $url = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            mime: (string) ($data['mime'] ?? ''),
            base64: isset($data['base64']) ? (string) $data['base64'] : null,
            url: isset($data['url']) ? (string) $data['url'] : null,
        );
    }
}