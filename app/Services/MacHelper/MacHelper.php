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

        try {
            $process = Process::timeout((int) config('mac_helper.timeout'))->run([
                'open', '-W', '-n', '-g', '--stdout', $output, '--stderr', $errors, config('mac_helper.app_path'), '--args', ...$arguments,
            ]);
        } catch (ProcessTimedOutException) {
            File::deleteDirectory($directory);

            throw new MacHelperException('The Mac helper didn\'t answer in time. If macOS is asking to allow access, click Allow and try again.');
        }

        try {
            $error = trim((string) @file_get_contents($errors));

            if ($error !== '' || $process->failed()) {
                throw new MacHelperException($error !== '' ? $error : (trim($process->errorOutput()) ?: 'The Mac helper failed.'));
            }

            $decoded = json_decode((string) @file_get_contents($output), true);

            if (! is_array($decoded)) {
                throw new MacHelperException('The Mac helper returned something unexpected.');
            }

            return $decoded;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
