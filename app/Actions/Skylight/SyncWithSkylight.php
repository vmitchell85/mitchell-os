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
}
