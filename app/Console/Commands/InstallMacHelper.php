<?php

namespace App\Console\Commands;

use App\Services\Calendar\MacCalendar;
use App\Services\MacHelper\MacHelper;
use App\Services\MacHelper\MacHelperException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('mac-helper:install {--force : Rebuild the helper even if it is installed (macOS will ask for access again)}')]
#[Description('Build the Mac helper app (macOS Calendar and Apple Maps), and ask macOS for calendar access')]
class InstallMacHelper extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MacHelper $helper, MacCalendar $calendar): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->components->error('The Mac helper only runs on macOS.');

            return self::FAILURE;
        }

        if (! $helper->isInstalled() || $this->option('force')) {
            if (! $this->build()) {
                return self::FAILURE;
            }
        }

        $this->components->info('Checking calendar access. If macOS asks whether "Mitchell OS Helper" can access your calendars, click Allow.');

        try {
            $calendars = $calendar->calendars();
        } catch (MacHelperException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Account', 'Calendar'], array_map(fn ($info): array => [$info->account, $info->title], $calendars));
        $this->components->info('Done. Choose which calendars the household shares in Settings > Calendars.');

        return self::SUCCESS;
    }

    /**
     * Compile the helper into a background app with its own Info.plist, signed so macOS can remember the permission.
     */
    protected function build(): bool
    {
        $app = (string) config('mac_helper.app_path');
        $building = dirname($app).'/building/'.basename($app);

        File::deleteDirectory(dirname($building));
        File::ensureDirectoryExists("{$building}/Contents/MacOS");
        File::copy((string) config('mac_helper.info_plist'), "{$building}/Contents/Info.plist");

        $built = false;

        $this->components->task('Building the Mac helper', function () use ($building, &$built): bool {
            return $built = Process::timeout(300)->run(['swiftc', '-O', config('mac_helper.source'), '-o', "{$building}/Contents/MacOS/mac-helper"])->successful()
                && Process::run(['codesign', '--force', '--sign', '-', $building])->successful();
        });

        if (! $built) {
            $this->components->error('The Mac helper didn\'t build. Check that the Xcode command line tools are installed (xcode-select --install).');

            return false;
        }

        File::deleteDirectory($app);
        File::moveDirectory($building, $app);
        File::deleteDirectory(dirname($building));

        return true;
    }
}
