<?php

namespace App\Services\Skylight;

final readonly class SkylightList
{
    public function __construct(
        public string $id,
        public string $label,
        public string $color,
        public string $kind,
        public bool $isDefaultGroceryList,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: (string) $attributes['id'],
            label: $attributes['label'],
            color: $attributes['color'] ?? '',
            kind: $attributes['kind'] ?? 'other',
            isDefaultGroceryList: (bool) ($attributes['default_grocery_list'] ?? false),
        );
    }

    public function isShoppingList(): bool
    {
        return $this->kind === 'shopping';
    }
}
