<?php

namespace App\Services\Claude;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;
use Throwable;

class ClaudeException extends RuntimeException
{
    /**
     * What the run had already cost before it failed.
     */
    public float $costUsd = 0;

    /**
     * Create an exception for a `result` event that reports an error, such as hitting the budget cap.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromErrorEvent(array $event): self
    {
        $reason = implode(' ', $event['errors'] ?? []) ?: ($event['result'] ?? null) ?: ($event['subtype'] ?? 'unknown error');

        $exception = new self("Claude returned an error: {$reason}");
        $exception->costUsd = (float) ($event['total_cost_usd'] ?? 0);

        return $exception;
    }

    /**
     * Create an exception for a process that exited without producing a result.
     */
    public static function fromFailedProcess(ProcessResult $process): self
    {
        $output = trim($process->errorOutput()) ?: trim($process->output());

        return new self("Claude exited with code {$process->exitCode()}: {$output}");
    }

    public static function timedOut(int $seconds, Throwable $previous): self
    {
        return new self("Claude did not finish within {$seconds} seconds.", previous: $previous);
    }
}
