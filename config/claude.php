<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude CLI Binary
    |--------------------------------------------------------------------------
    |
    | Absolute path to the `claude` executable. Web servers and queue workers
    | usually don't have ~/.local/bin on their PATH, so set this explicitly.
    |
    */

    'binary' => env('CLAUDE_BINARY', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    |
    | Every run happens in this directory so Claude never picks up this repo's
    | CLAUDE.md, MCP servers or hooks. Saved sessions are keyed by directory,
    | so changing it orphans existing chat sessions.
    |
    */

    'working_directory' => env('CLAUDE_WORKING_DIRECTORY', storage_path('app/claude')),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Used when a request doesn't specify its own. The timeout is in seconds
    | and the budget caps the spend of a single run in US dollars.
    |
    */

    'model' => env('CLAUDE_MODEL', 'sonnet'),

    'system_prompt' => env('CLAUDE_SYSTEM_PROMPT', 'You are a helpful assistant for the Mitchell household.'),

    'timeout' => (int) env('CLAUDE_TIMEOUT', 300),

    'max_budget_usd' => (float) env('CLAUDE_MAX_BUDGET_USD', 1.0),

];
