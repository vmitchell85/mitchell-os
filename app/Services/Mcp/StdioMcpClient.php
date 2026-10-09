<?php

namespace App\Services\Mcp;

use App\Services\AccountEnvironment;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use JsonException;
use Symfony\Component\Process\InputStream;

/**
 * Talks to an MCP server over stdio (newline-delimited JSON-RPC), so the app
 * can call a server's tools directly, without going through Claude. The
 * server starts on the first call and is reused until close().
 */
class StdioMcpClient implements McpClient
{
    protected ?InvokedProcess $process = null;

    protected InputStream $input;

    protected string $buffer = '';

    protected int $nextRequestId = 1;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function __construct(
        protected array $command,
        protected array $environment = [],
        protected int $requestTimeoutSeconds = 60,
    ) {}

    /**
     * Create a client for a server defined in config/claude.php.
     *
     * @throws McpException
     */
    public static function forServer(string $name): self
    {
        $server = config("claude.mcp_servers.{$name}")
            ?? throw new McpException("The MCP server [{$name}] is not configured in config/claude.php.");

        $command = [$server['command'] ?? null, ...($server['args'] ?? [])];
        $stringParts = array_values(array_filter($command, is_string(...)));

        if (count($stringParts) !== count($command)) {
            throw new McpException("The MCP server [{$name}] needs a command and args made of strings in config/claude.php.");
        }

        $environment = array_filter($server['env'] ?? [], fn (mixed $value): bool => is_string($value) && $value !== '');

        return new self(
            command: $stringParts,
            environment: [...$environment, ...AccountEnvironment::current()],
        );
    }

    public function callTool(string $name, array $arguments = []): mixed
    {
        $result = $this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);

        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $textBlocks = array_filter($content, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text');
        $text = implode("\n", array_column($textBlocks, 'text'));

        if ($result['isError'] ?? false) {
            throw new McpException("The [{$name}] tool failed: {$text}");
        }

        if (isset($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        try {
            return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $text;
        }
    }

    public function close(): void
    {
        if ($this->process?->running()) {
            $this->input->close();
            $this->process->signal(SIGTERM);
        }

        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function start(): void
    {
        $this->input = new InputStream;
        $this->buffer = '';

        $this->process = Process::env($this->environment)
            ->forever()
            ->input($this->input)
            ->start($this->command);

        $this->send([
            'jsonrpc' => '2.0',
            'id' => $this->nextRequestId++,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => config('app.name'), 'version' => '1.0.0'],
            ],
        ]);
        $this->awaitResponse($this->nextRequestId - 1, 'initialize');

        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function request(string $method, array $params): array
    {
        if ($this->process === null) {
            $this->start();
        }

        $id = $this->nextRequestId++;

        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);

        return $this->awaitResponse($id, $method);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function send(array $message): void
    {
        $this->input->write(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Read lines until the response with the given id arrives, skipping the
     * server's notifications and anything that isn't JSON-RPC.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function awaitResponse(int $id, string $method): array
    {
        $deadline = microtime(true) + $this->requestTimeoutSeconds;

        while (true) {
            $this->buffer .= $this->process->latestOutput();

            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $message = json_decode(substr($this->buffer, 0, $newline), true);
                $this->buffer = substr($this->buffer, $newline + 1);

                if (! is_array($message) || isset($message['method'])) {
                    continue;
                }

                $isOurs = ($message['id'] ?? null) === $id;
                $isUnattributedError = isset($message['error']) && ($message['id'] ?? null) === null;

                if (! $isOurs && ! $isUnattributedError) {
                    continue;
                }

                if (isset($message['error'])) {
                    throw new McpException("MCP [{$method}] failed: ".($message['error']['message'] ?? 'unknown error'));
                }

                return $message['result'] ?? [];
            }

            if (! $this->process->running()) {
                $errorOutput = trim($this->process->latestErrorOutput());
                $this->process = null;

                throw new McpException("The MCP server exited during [{$method}]".($errorOutput !== '' ? ": {$errorOutput}" : '.'));
            }

            if (microtime(true) > $deadline) {
                $this->close();

                throw new McpException("The MCP server did not answer [{$method}] within {$this->requestTimeoutSeconds} seconds.");
            }

            Sleep::usleep(10_000);
        }
    }
}
