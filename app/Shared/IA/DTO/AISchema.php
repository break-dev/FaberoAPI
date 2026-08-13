<?php

namespace App\Shared\IA\DTO;

final class AISchema
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema (object) describing the function arguments.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $parameters,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $name = (string) ($data['name'] ?? '');
        $description = isset($data['description']) ? (string) $data['description'] : null;
        $parameters = (array) ($data['parameters'] ?? []);

        return new self($name, $description, $parameters);
    }
}