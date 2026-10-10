<?php

namespace App\Services\MacHelper;

use Carbon\CarbonImmutable;

/**
 * Traffic-aware drive times from Apple Maps, through the Mac helper.
 */
class MacDirections
{
    public function __construct(protected MacHelper $helper) {}

    /**
     * How long it takes to drive between two addresses to arrive by the given time.
     *
     * @throws MacHelperException
     */
    public function drive(string $from, string $to, CarbonImmutable $arriveBy): DriveEstimate
    {
        $result = $this->helper->run(['drive', $from, $to, $arriveBy->toIso8601String()]);

        if (! isset($result['minutes'], $result['miles']) || ! is_numeric($result['minutes']) || ! is_numeric($result['miles'])) {
            throw new MacHelperException('The Mac helper returned something unexpected.');
        }

        return new DriveEstimate(
            minutes: (int) $result['minutes'],
            miles: (float) $result['miles'],
            trafficAware: (bool) ($result['traffic_aware'] ?? false),
        );
    }
}
