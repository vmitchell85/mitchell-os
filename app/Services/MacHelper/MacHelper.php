<?php

namespace App\Services\MacHelper;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs the helper app built by mac-helper:install, which reaches macOS
 * features PHP can't (Calendar, Apple Maps). The helper is launched with
 * `open` so macOS treats it as its own app and asks for permissions on its
 * behalf; its output comes back through temporary files because `open`
 * doesn't pass it through.
 */
class MacHelper
{
    public function isInstalled(): bool
    {
        return is_file($this->executable());
    }

    public function executable(): string
    {
        return config('mac_helper.app_path').'/Contents/MacOS/mac-helper';
    }

    /**
     * Run a helper subcommand and return its decoded JSON output.
     *
     * `open -W` can lose a race when the helper exits before `open` starts
     * waiting on it (kevent fails with "No such process"). That is only a
     * warning: the helper still runs and writes its output files, sometimes a
     * moment later, so the output is waited for until the timeout.
     *
     * @param  list<string>  $arguments
     * @return array<array-key, mixed>
     *
     * @throws MacHelperException
     */
    public function run(array $arguments): array
    {
        if (! $this->isInstalled()) {
            throw MacHelperException::notInstalled();
        }

        $directory = storage_path('app/mac-helper/runs/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $output = "{$directory}/stdout";
        $errors = "{$directory}/stderr";
        $timeout = (int) config('mac_helper.timeout');

        try {
            $process = Process::timeout($timeout)->run([
                'open', '-W', '-n', '-g', '--stdout', $output, '--stderr', $errors, config('mac_helper.app_path'), '--args', ...$arguments,
            ]);

            $error = trim((string) @file_get_contents($errors));

            if ($error === '' && $process->failed() && str_contains($process->errorOutput(), 'Unable to block on applications')) {
                return $this->awaitOutput($output, $errors, $timeout);
            }

            if ($error !== '' || $process->failed()) {
                throw new MacHelperException($error !== '' ? $error : (trim($process->errorOutput()) ?: 'The Mac helper failed.'));
            }

            return $this->decode($output);
        } catch (ProcessTimedOutException) {
            throw $this->timedOut();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws MacHelperException
     */
    protected function awaitOutput(string $output, string $errors, int $timeout): array
    {
        $deadline = microtime(true) + $timeout;

        do {
            $error = trim((string) @file_get_contents($errors));

            if ($error !== '') {
                throw new MacHelperException($error);
            }

            $decoded = json_decode((string) @file_get_contents($output), true);

            if (is_array($decoded)) {
                return $decoded;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw $this->timedOut();
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws MacHelperException
     */
    protected function decode(string $output): array
    {
        $decoded = json_decode((string) @file_get_contents($output), true);

        if (! is_array($decoded)) {
            throw new MacHelperException('The Mac helper returned something unexpected.');
        }

        return $decoded;
    }

    protected function timedOut(): MacHelperException
    {
        return new MacHelperException('The Mac helper didn\'t answer in time. If macOS is asking to allow access, click Allow and try again.');
    }
}
