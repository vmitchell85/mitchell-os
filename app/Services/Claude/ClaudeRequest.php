<?php

namespace App\Services\Claude;

use App\Models\User;

final readonly class ClaudeRequest
{
    /**
     * @param  string  $feature  Recorded on the run so spend can be broken down by feature, e.g. 'chat'.
     * @param  User|null  $user  The household member the run is for; null for background work.
     * @param  list<string>  $tools  Tools to enable and pre-approve: built-in ones like 'WebSearch', or MCP
     *                               tools named mcp__{server}__{tool}, which start that server from config/claude.php.
     * @param  array<string, mixed>|null  $jsonSchema  When set, the result's structured output must match this schema.
     * @param  bool  $persistSession  Save the session so a later request can resume it.
     * @param  array<string, string>  $environment  Extra environment for the CLI and the MCP servers it starts.
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
        public array $environment = [],
    ) {}

    /**
     * @return list<string>
     */
    public function builtInTools(): array
    {
        return array_values(array_filter($this->tools, fn (string $tool): bool => ! str_starts_with($tool, 'mcp__')));
    }

    /**
     * The MCP servers whose tools this request allows.
     *
     * @return list<string>
     */
    public function mcpServers(): array
    {
        $servers = array_map(
            fn (string $tool): string => explode('__', $tool)[1],
            array_filter($this->tools, fn (string $tool): bool => str_starts_with($tool, 'mcp__')),
        );

        return array_values(array_unique($servers));
    }
}
