<?php

namespace App\Services\Skylight;

final readonly class SkylightListItem
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $isCompleted,
        public ?string $section,
        public int $position,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: (string) $attributes['id'],
            label: $attributes['label'],
            isCompleted: ($attributes['status'] ?? 'pending') === 'completed',
            section: ($attributes['section'] ?? null) ?: null,
            position: (int) ($attributes['position'] ?? 0),
        );
    }
}
