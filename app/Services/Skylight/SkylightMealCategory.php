<?php

namespace App\Services\Skylight;

final readonly class SkylightMealCategory
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $isEnabled,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: (string) $attributes['id'],
            label: $attributes['label'],
            isEnabled: (bool) ($attributes['enabled'] ?? true),
        );
    }
}
