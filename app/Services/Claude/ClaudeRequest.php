<?php

namespace App\Services\Claude;

use App\Models\User;

final readonly class ClaudeRequest
{
    /**
     * @param  string  $feature  Recorded on the run so spend can be broken down by feature, e.g. 'chat'.
     * @param  User|null  $user  The household member the run is for; null for background work.
     * @param  list<string>  $tools  Claude Code tool names to enable and pre-approve, e.g. ['WebSearch'].
     * @param  array<string, mixed>|null  $jsonSchema  When set, the result's structured output must match this schema.
     * @param  bool  $persistSession  Save the session so a later request can resume it.
     */
    public function __construct(
        public string $prompt,
        public string $feature = 'general',
        public ?User $user = null,
        public ?string $systemPrompt = null,
        public ?string $model = null,
        public array $tools = [],
        public ?array $jsonSchema = null,
        public ?string $resumeSessionId = null,
        public bool $persistSession = false,
        public ?float $maxBudgetUsd = null,
    ) {}
}
