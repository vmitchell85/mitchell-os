<?php

namespace Tests\Fakes;

use App\Services\Mcp\McpClient;
use Closure;
use LogicException;

/**
 * An in-memory MCP client that answers tool calls with canned responses
 * and records every call it receives.
 */
class FakeMcpClient implements McpClient
{
    /**
     * @var list<array{name: string, arguments: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $responses  Keyed by tool name; a Closure receives the arguments.
     */
    public function __construct(protected array $responses = []) {}

    public function callTool(string $name, array $arguments = []): mixed
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];

        if (! array_key_exists($name, $this->responses)) {
            throw new LogicException("FakeMcpClient has no response for the [{$name}] tool.");
        }

        $response = $this->responses[$name];

        return $response instanceof Closure ? $response($arguments) : $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function argumentsFor(string $name): array
    {
        return array_values(array_map(
            fn (array $call): array => $call['arguments'],
            array_filter($this->calls, fn (array $call): bool => $call['name'] === $name),
        ));
    }
}
