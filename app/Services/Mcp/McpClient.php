<?php

namespace App\Services\Mcp;

interface McpClient
{
    /**
     * Call a tool and return its result: the structured content when the
     * server provides it, otherwise the text content decoded as JSON, or
     * the raw text when it isn't JSON.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws McpException
     */
    public function callTool(string $name, array $arguments = []): mixed;
}
