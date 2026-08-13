<?php

namespace App\Shared\IA\DTO;

final class AIChatRequest
{
    /**
     * @param  list<AIMessage>  $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?AISchema $schema = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxTokens = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawMessages = $data['messages'] ?? [];
        $messages = [];
        foreach ($rawMessages as $raw) {
            $messages[] = AIMessage::fromArray((array) $raw);
        }

        $schema = null;
        if (! empty($data['schema']) && is_array($data['schema'])) {
            $schema = AISchema::fromArray($data['schema']);
        }

        $temperature = isset($data['temperature']) ? (float) $data['temperature'] : null;
        $maxTokens = isset($data['maxTokens']) ? (int) $data['maxTokens'] : null;

        return new self($messages, $schema, $temperature, $maxTokens);
    }
}