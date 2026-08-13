<?php

namespace App\Shared\IA\DTO;

final class AIMessage
{
    /**
     * @param  list<AIAttachment>  $attachments
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly array $attachments = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $role = (string) ($data['role'] ?? '');
        $content = (string) ($data['content'] ?? '');
        $rawAttachments = $data['attachments'] ?? [];

        $attachments = [];
        foreach ($rawAttachments as $raw) {
            $attachments[] = AIAttachment::fromArray((array) $raw);
        }

        return new self($role, $content, $attachments);
    }
}