<?php

namespace App\Actions\Skylight;

/**
 * What a sync changed, on each side.
 */
final class SyncReport
{
    /**
     * Items created or updated here from Skylight.
     */
    public int $pulled = 0;

    /**
     * Items created or updated in Skylight from here.
     */
    public int $pushed = 0;

    public int $removedHere = 0;

    public int $removedFromSkylight = 0;

    /**
     * Items deleted on one side but edited on the other, which sync keeps.
     */
    public int $kept = 0;

    public function hasChanges(): bool
    {
        return $this->pulled + $this->pushed + $this->removedHere + $this->removedFromSkylight + $this->kept > 0;
    }

    public function summary(): string
    {
        if (! $this->hasChanges()) {
            return __('Already up to date.');
        }

        return collect([
            $this->pulled ? trans_choice(':count change from Skylight|:count changes from Skylight', $this->pulled) : null,
            $this->pushed ? trans_choice(':count change sent to Skylight|:count changes sent to Skylight', $this->pushed) : null,
            $this->removedHere ? trans_choice(':count item removed here|:count items removed here', $this->removedHere) : null,
            $this->removedFromSkylight ? trans_choice(':count item removed from Skylight|:count items removed from Skylight', $this->removedFromSkylight) : null,
            $this->kept ? trans_choice(':count deleted item kept because it was edited|:count deleted items kept because they were edited', $this->kept) : null,
        ])->filter()->implode(', ').'.';
    }
}
