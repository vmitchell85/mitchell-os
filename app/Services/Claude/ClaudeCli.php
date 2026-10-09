<?php

namespace App\Services\Claude;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ClaudeCli
{
    /**
     * Run a single prompt and wait for the complete result.
     *
     * @throws ClaudeException
     */
    public function ask(ClaudeRequest $request): ClaudeResult
    {
        $process = $this->pendingProcess($request)->run($this->command($request, 'json'));

        $event = json_decode($process->output(), true);

        return $this->resultFrom(is_array($event) ? $event : null, $process);
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

        return $this->resultFrom($resultEvent, $process);
    }

    protected function pendingProcess(ClaudeRequest $request): PendingProcess
    {
        $workingDirectory = config('claude.working_directory');

        File::ensureDirectoryExists($workingDirectory);

        return Process::path($workingDirectory)
            ->timeout(config('claude.timeout'))
            ->input($request->prompt);
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
            '--system-prompt', $request->systemPrompt ?? config('claude.system_prompt'),
            '--max-budget-usd', (string) ($request->maxBudgetUsd ?? config('claude.max_budget_usd')),
            '--setting-sources', '',
            '--strict-mcp-config',
            '--tools', implode(',', $request->tools),
        ];

        if ($request->tools !== []) {
            array_push($command, '--allowedTools', implode(',', $request->tools));
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
     * @param  array<string, mixed>|null  $event
     *
     * @throws ClaudeException
     */
    protected function resultFrom(?array $event, ProcessResult $process): ClaudeResult
    {
        if ($event === null) {
            throw ClaudeException::fromFailedProcess($process);
        }

        if ($event['is_error'] ?? false) {
            throw ClaudeException::fromErrorEvent($event);
        }

        return ClaudeResult::fromEvent($event);
    }
}
