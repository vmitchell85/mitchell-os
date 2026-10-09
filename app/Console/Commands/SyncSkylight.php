<?php

namespace App\Console\Commands;

use App\Actions\Skylight\SyncWithSkylight;
use App\Services\Mcp\McpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

#[Signature('skylight:sync')]
#[Description('Sync shopping lists and meals two ways with the family\'s Skylight frame')]
class SyncSkylight extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncWithSkylight $sync): int
    {
        try {
            $report = $sync->handle();
        } catch (LockTimeoutException) {
            $this->components->error('Another Skylight sync is still running.');

            return self::FAILURE;
        } catch (McpException $exception) {
            $this->components->error("Skylight sync failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info($report->summary());

        return self::SUCCESS;
    }
}
