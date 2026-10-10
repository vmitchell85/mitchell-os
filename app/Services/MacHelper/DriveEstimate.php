<?php

namespace App\Services\MacHelper;

final readonly class DriveEstimate
{
    public function __construct(
        public int $minutes,
        public float $miles,
        public bool $trafficAware,
    ) {}
}
