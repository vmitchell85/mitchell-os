<?php

namespace App\Services\Calendar;

/**
 * A calendar in macOS Calendar, from any account synced to this Mac.
 */
final readonly class MacCalendarInfo
{
    public function __construct(
        public string $id,
        public string $title,
        public string $account,
        public ?string $color,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: (string) $attributes['id'],
            title: (string) $attributes['title'],
            account: (string) ($attributes['account'] ?? ''),
            color: isset($attributes['color']) ? (string) $attributes['color'] : null,
        );
    }
}
