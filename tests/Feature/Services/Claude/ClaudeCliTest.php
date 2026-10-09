<?php

use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\StreamEvent;
use App\Services\Claude\StreamEventType;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'claude.binary' => '/opt/claude/bin/claude',
        'claude.working_directory' => sys_get_temp_dir().'/mitchell-os-claude-tests',
        'claude.model' => 'sonnet',
        'claude.system_prompt' => 'You help the household.',
        'claude.max_budget_usd' => 0.5,
    ]);
});

/**
 * Fake the claude CLI and return a holder that captures the process it was started with.
 */
function fakeClaudeProcess(string $output = '', int $exitCode = 0, string $errorOutput = ''): stdClass
{
    $captured = new stdClass;

    Process::preventStrayProcesses();
    Process::fake(['*' => function (PendingProcess $process) use ($captured, $output, $exitCode, $errorOutput) {
        $captured->process = $process;

        return Process::describe()->output($output)->errorOutput($errorOutput)->exitCode($exitCode);
    }]);

    return $captured;
}

/**
 * Get the value that follows a flag in a command, or null when the flag is absent.
 *
 * @param  list<string>  $command
 */
function claudeOption(array $command, string $flag): ?string
{
    $index = array_search($flag, $command, true);

    return $index === false ? null : $command[$index + 1];
}

function claudeFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/claude/{$name}"));
}

describe('ask', function () {
    it('returns the answer, structured output, session and usage', function () {
        fakeClaudeProcess(claudeFixture('ask-structured.json'));

        $result = app(ClaudeCli::class)->ask(new ClaudeRequest('Name a fruit and its colour'));

        expect($result)
            ->text->toBe('{"fruit":"Banana","colour":"Yellow"}')
            ->structured->toBe(['fruit' => 'Banana', 'colour' => 'Yellow'])
            ->sessionId->toBe('effd063a-f84b-4d44-be43-a53d44acb7b6')
            ->costUsd->toBe(0.0008510000000000001)
            ->inputTokens->toBe(4056)
            ->outputTokens->toBe(80)
            ->durationMs->toBe(790);
    });

    it('sends the prompt on stdin from the isolated working directory', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('What\'s for "dinner"?'));

        expect($captured->process)
            ->input->toBe('What\'s for "dinner"?')
            ->path->toBe(sys_get_temp_dir().'/mitchell-os-claude-tests')
            ->command->not->toContain('What\'s for "dinner"?');
    });

    it('locks down settings, MCP servers, tools and session persistence by default', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello'));

        $command = $captured->process->command;
        expect($command[0])->toBe('/opt/claude/bin/claude');
        expect($command)
            ->toContain('-p', '--strict-mcp-config', '--no-session-persistence')
            ->not->toContain('--allowedTools', '--resume', '--json-schema');
        expect([
            claudeOption($command, '--output-format'),
            claudeOption($command, '--setting-sources'),
            claudeOption($command, '--tools'),
            claudeOption($command, '--model'),
            claudeOption($command, '--system-prompt'),
            claudeOption($command, '--max-budget-usd'),
        ])->toBe(['json', '', '', 'sonnet', 'You help the household.', '0.5']);
    });

    it('passes the model, system prompt, budget and json schema from the request', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest(
            prompt: 'Name a fruit',
            systemPrompt: 'You are a greengrocer.',
            model: 'haiku',
            jsonSchema: ['type' => 'object', 'properties' => ['fruit' => ['type' => 'string']]],
            maxBudgetUsd: 0.05,
        ));

        $command = $captured->process->command;
        expect([
            claudeOption($command, '--model'),
            claudeOption($command, '--system-prompt'),
            claudeOption($command, '--max-budget-usd'),
            claudeOption($command, '--json-schema'),
        ])->toBe(['haiku', 'You are a greengrocer.', '0.05', '{"type":"object","properties":{"fruit":{"type":"string"}}}']);
    });

    it('enables and pre-approves only the requested tools', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('Search the web', tools: ['WebSearch', 'WebFetch']));

        $command = $captured->process->command;
        expect([claudeOption($command, '--tools'), claudeOption($command, '--allowedTools')])
            ->toBe(['WebSearch,WebFetch', 'WebSearch,WebFetch']);
    });

    it('resumes and keeps the session when asked to persist it', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('And then?', resumeSessionId: 'effd063a-f84b-4d44-be43-a53d44acb7b6', persistSession: true));

        $command = $captured->process->command;
        expect(claudeOption($command, '--resume'))->toBe('effd063a-f84b-4d44-be43-a53d44acb7b6');
        expect($command)->not->toContain('--no-session-persistence');
    });

    it('throws with the CLI output when the process fails without a result', function () {
        fakeClaudeProcess(exitCode: 1, errorOutput: 'No conversation found with session ID: missing');

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello', resumeSessionId: 'missing'));
    })->throws(ClaudeException::class, 'Claude exited with code 1: No conversation found with session ID: missing');

    it('throws the reported reason when claude returns an error result', function () {
        fakeClaudeProcess(claudeFixture('budget-exceeded.json'), exitCode: 1);

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello'));
    })->throws(ClaudeException::class, 'Claude returned an error: Reached maximum budget ($0.00001)');
});

describe('stream', function () {
    it('emits tool use and text deltas as they arrive and returns the final result', function () {
        fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));
        $events = [];

        $result = app(ClaudeCli::class)->stream(
            new ClaudeRequest('What is the top headline?', tools: ['WebSearch']),
            function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            },
        );

        expect($events)->toEqual([
            new StreamEvent(StreamEventType::ToolUse, toolName: 'WebSearch'),
            new StreamEvent(StreamEventType::TextDelta, text: 'The top story is '),
            new StreamEvent(StreamEventType::TextDelta, text: 'a by-election result.'),
        ]);
        expect($result)
            ->text->toBe('The top story is a by-election result.')
            ->sessionId->toBe('539a1777-5933-47e9-832a-fa0e4010c2d3');
    });

    it('requests verbose stream-json output with partial messages', function () {
        $captured = fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));

        app(ClaudeCli::class)->stream(new ClaudeRequest('Hello'), fn () => null);

        $command = $captured->process->command;
        expect(claudeOption($command, '--output-format'))->toBe('stream-json');
        expect($command)->toContain('--verbose', '--include-partial-messages');
    });

    it('throws with the CLI output when the stream ends without a result', function () {
        fakeClaudeProcess(exitCode: 1, errorOutput: 'Not logged in');

        app(ClaudeCli::class)->stream(new ClaudeRequest('Hello'), fn () => null);
    })->throws(ClaudeException::class, 'Claude exited with code 1: Not logged in');
});
