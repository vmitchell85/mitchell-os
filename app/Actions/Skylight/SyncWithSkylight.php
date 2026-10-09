<?php

namespace App\Actions\Skylight;

use App\Services\Mcp\McpException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Run every Skylight sync, one at a time across the whole app.
 */
class SyncWithSkylight
{
    public function __construct(
        protected SyncShoppingLists $syncShoppingLists,
        protected SyncMeals $syncMeals,
    ) {}

    /**
     * @throws McpException
     * @throws LockTimeoutException when another sync is still running.
     */
    public function handle(): SyncReport
    {
        return Cache::lock('skylight-sync', seconds: 300)->block(1, function (): SyncReport {
            $report = $this->syncShoppingLists->handle();

            return $this->syncMeals->handle($report);
        });
    }

    /**
     * Sync, turning failures into an outcome to show rather than an exception.
     */
    public function attempt(): SyncOutcome
    {
        try {
            $report = $this->handle();
        } catch (LockTimeoutException) {
            return new SyncOutcome(false, 'warning', null, __('A sync is already running. Try again in a moment.'));
        } catch (McpException $exception) {
            report($exception);

            return new SyncOutcome(false, 'danger', __('Sync failed'), $exception->getMessage());
        }

        return new SyncOutcome(true, 'success', __('Synced with Skylight'), $report->summary());
    }
}
