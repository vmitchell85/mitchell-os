<?php

namespace App\Services\Claude;

use App\Models\ClaudeRun;

final readonly class ClaudeResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     */
    public function __construct(
        public string $text,
        public ?array $structured,
        public string $sessionId,
        public float $costUsd,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
        public ClaudeRun $run,
    ) {}

    /**
     * Build a result from the CLI's final `result` event.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event, ClaudeRun $run): self
    {
        $usage = $event['usage'] ?? [];

        return new self(
            text: $event['result'] ?? '',
            structured: $event['structured_output'] ?? null,
            sessionId: $event['session_id'],
            costUsd: (float) ($event['total_cost_usd'] ?? 0),
            inputTokens: ($usage['input_tokens'] ?? 0)
                + ($usage['cache_creation_input_tokens'] ?? 0)
                + ($usage['cache_read_input_tokens'] ?? 0),
            outputTokens: $usage['output_tokens'] ?? 0,
            durationMs: $event['duration_ms'] ?? 0,
            run: $run,
        );
    }
}
