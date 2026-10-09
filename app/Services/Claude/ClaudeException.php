<?php

namespace App\Services\Claude;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

class ClaudeException extends RuntimeException
{
    /**
     * Create an exception for a `result` event that reports an error, such as hitting the budget cap.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromErrorEvent(array $event): self
    {
        $reason = implode(' ', $event['errors'] ?? []) ?: ($event['result'] ?? null) ?: ($event['subtype'] ?? 'unknown error');

        return new self("Claude returned an error: {$reason}");
    }

    /**
     * Create an exception for a process that exited without producing a result.
     */
    public static function fromFailedProcess(ProcessResult $process): self
    {
        $output = trim($process->errorOutput()) ?: trim($process->output());

        return new self("Claude exited with code {$process->exitCode()}: {$output}");
    }
}
