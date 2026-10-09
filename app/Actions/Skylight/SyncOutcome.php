<?php

namespace App\Actions\Skylight;

/**
 * How a sync went, worded for the person who started it.
 */
final readonly class SyncOutcome
{
    public function __construct(
        public bool $succeeded,
        public string $variant,
        public ?string $heading,
        public string $message,
    ) {}
}
