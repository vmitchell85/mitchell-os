<?php

namespace App\Services\Claude;

use App\Models\ClaudeRun;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class ClaudeCli
{
    /**
     * Run a single prompt and wait for the complete result.
     *
     * @throws ClaudeException
     */
    public function ask(ClaudeRequest $request): ClaudeResult
    {
        return $this->recorded($request, function () use ($request): array {
            $process = $this->pendingProcess($request)->run($this->command($request, 'json'));

            $event = json_decode($process->output(), true);

            return $this->successfulResultEvent(is_array($event) ? $event : null, $process);
        });
    }

    /**
     * Run a prompt, passing text deltas and tool use to the callback as they arrive.
     *
     * @param  callable(StreamEvent): void  $onEvent
     *
     * @throws ClaudeException
     */
    public function stream(ClaudeRequest $request, callable $onEvent): ClaudeResult
    {
        return $this->recorded($request, function () use ($request, $onEvent): array {
            $buffer = '';
            $resultEvent = null;

            $handleLine = function (string $line) use ($onEvent, &$resultEvent): void {
                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    return;
                }

                if (($decoded['type'] ?? null) === 'result') {
                    $resultEvent = $decoded;

                    return;
                }

                if ($event = StreamEvent::fromLine($decoded)) {
                    $onEvent($event);
                }
            };

            $process = $this->pendingProcess($request)
                ->start($this->command($request, 'stream-json'), function (string $type, string $output) use (&$buffer, $handleLine): void {
                    if ($type !== 'out') {
                        return;
                    }

                    $buffer .= $output;

                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $handleLine(substr($buffer, 0, $newline));
                        $buffer = substr($buffer, $newline + 1);
                    }
                })
                ->wait();

            $handleLine($buffer);

            return $this->successfulResultEvent($resultEvent, $process);
        });
    }

    protected function pendingProcess(ClaudeRequest $request): PendingProcess
    {
        $workingDirectory = config('claude.working_directory');

        File::ensureDirectoryExists($workingDirectory);

        return Process::path($workingDirectory)
            ->env([...$this->mcpEnvironment($request), ...$this->accountEnvironment()])
            ->timeout(config('claude.timeout'))
            ->input($request->prompt);
    }

    /**
     * Web servers such as `php artisan serve` strip the environment, but the CLI
     * needs all three of these to find its login in the macOS keychain.
     *
     * @return array{HOME: string, USER: string, LOGNAME: string}
     *
     * @throws ClaudeException
     */
    protected function accountEnvironment(): array
    {
        $account = posix_getpwuid(posix_geteuid());

        if ($account === false) {
            throw new ClaudeException('Could not look up the OS account PHP is running as, so the Claude CLI cannot find its login.');
        }

        return [
            'HOME' => $account['dir'],
            'USER' => $account['name'],
            'LOGNAME' => $account['name'],
        ];
    }

    /**
     * The configured MCP servers the request needs, keyed by name.
     *
     * @return array<string, array{command: string, args: list<string>, env: array<string, string|null>}>
     *
     * @throws ClaudeException
     */
    protected function mcpServers(ClaudeRequest $request): array
    {
        $servers = [];

        foreach ($request->mcpServers() as $name) {
            $servers[$name] = config("claude.mcp_servers.{$name}")
                ?? throw new ClaudeException("The MCP server [{$name}] is not configured in config/claude.php.");
        }

        return $servers;
    }

    /**
     * Environment for the MCP servers, which inherit it from the CLI.
     *
     * @return array<string, string>
     *
     * @throws ClaudeException
     */
    protected function mcpEnvironment(ClaudeRequest $request): array
    {
        $environment = array_merge(...array_values(array_map(fn (array $server): array => $server['env'], $this->mcpServers($request))));

        return array_filter($environment, fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Build the CLI arguments. Project settings, MCP servers and tools are
     * locked down unless the request explicitly asks for them.
     *
     * @return list<string>
     */
    protected function command(ClaudeRequest $request, string $outputFormat): array
    {
        $command = [
            config('claude.binary'), '-p',
            '--output-format', $outputFormat,
            '--model', $request->model ?? config('claude.model'),
            '--system-prompt', $this->systemPrompt($request),
            '--max-budget-usd', (string) ($request->maxBudgetUsd ?? config('claude.max_budget_usd')),
            '--setting-sources', '',
            '--strict-mcp-config',
            '--tools', implode(',', $request->builtInTools()),
        ];

        if ($request->tools !== []) {
            array_push($command, '--allowedTools', implode(',', $request->tools));
        }

        if ($servers = $this->mcpServers($request)) {
            $mcpConfig = array_map(fn (array $server): array => [
                'type' => 'stdio',
                'command' => $server['command'],
                'args' => $server['args'],
            ], $servers);

            array_push($command, '--mcp-config', json_encode(['mcpServers' => $mcpConfig], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        if ($outputFormat === 'stream-json') {
            array_push($command, '--verbose', '--include-partial-messages');
        }

        if ($request->jsonSchema !== null) {
            array_push($command, '--json-schema', json_encode($request->jsonSchema, JSON_THROW_ON_ERROR));
        }

        if ($request->resumeSessionId !== null) {
            array_push($command, '--resume', $request->resumeSessionId);
        }

        if (! $request->persistSession) {
            $command[] = '--no-session-persistence';
        }

        return $command;
    }

    /**
     * Replacing Claude Code's system prompt also drops the date it would
     * include, which questions like "what's on tomorrow?" depend on.
     */
    protected function systemPrompt(ClaudeRequest $request): string
    {
        $now = now()->timezone(config('app.timezone'));

        return ($request->systemPrompt ?? config('claude.system_prompt'))
            ."\n\nIt is currently {$now->format('l j F Y, H:i')} ({$now->getTimezone()->getName()}).";
    }

    /**
     * Log the run before it starts and record how it ended, so every call
     * shows up in the household's spend, including ones that fail.
     *
     * @param  Closure(): array<string, mixed>  $execute  Runs the CLI and returns its successful `result` event.
     *
     * @throws ClaudeException
     */
    protected function recorded(ClaudeRequest $request, Closure $execute): ClaudeResult
    {
        $run = ClaudeRun::create([
            'user_id' => $request->user?->id,
            'feature' => $request->feature,
            'model' => $request->model ?? config('claude.model'),
            'status' => ClaudeRunStatus::Running,
        ]);

        try {
            $result = ClaudeResult::fromEvent($execute(), $run);
        } catch (ProcessTimedOutException $exception) {
            $timeout = ClaudeException::timedOut(config('claude.timeout'), $exception);
            $run->markFailed($timeout->getMessage());

            throw $timeout;
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage(), $exception instanceof ClaudeException ? $exception->costUsd : 0);

            throw $exception;
        }

        $run->markSucceeded($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $event
     * @return array<string, mixed>
     *
     * @throws ClaudeException
     */
    protected function successfulResultEvent(?array $event, ProcessResult $process): array
    {
        if ($event === null) {
            throw ClaudeException::fromFailedProcess($process);
        }

        if ($event['is_error'] ?? false) {
            throw ClaudeException::fromErrorEvent($event);
        }

        return $event;
    }
}
