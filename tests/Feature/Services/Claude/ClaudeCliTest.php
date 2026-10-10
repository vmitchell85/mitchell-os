<?php

use App\Models\ClaudeRun;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\ClaudeRunStatus;
use App\Services\Claude\StreamEvent;
use App\Services\Claude\StreamEventType;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    config([
        'claude.binary' => '/opt/claude/bin/claude',
        'claude.working_directory' => sys_get_temp_dir().'/mitchell-os-claude-tests',
        'claude.model' => 'sonnet',
        'claude.system_prompt' => 'You help the household.',
    ]);
});

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

    it('runs as the OS account so the CLI finds its login when the web server strips the environment', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));
        $account = posix_getpwuid(posix_geteuid());

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello'));

        expect($captured->process->environment)->toBe([
            'HOME' => $account['dir'],
            'USER' => $account['name'],
            'LOGNAME' => $account['name'],
        ]);
    });

    it('adds the request\'s environment, without letting it change the OS account', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));
        $account = posix_getpwuid(posix_geteuid());

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello', environment: ['HOUSEHOLD_USER_ID' => '7', 'HOME' => '/tmp/elsewhere']));

        expect($captured->process->environment)
            ->toMatchArray(['HOUSEHOLD_USER_ID' => '7', 'HOME' => $account['dir']]);
    });

    it('locks down settings, MCP servers, tools and session persistence by default', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('Hello'));

        $command = $captured->process->command;
        expect($command[0])->toBe('/opt/claude/bin/claude');
        expect($command)
            ->toContain('-p', '--strict-mcp-config', '--no-session-persistence')
            ->not->toContain('--allowedTools', '--resume', '--json-schema');
        expect($command)->not->toContain('--mcp-config');
        expect([
            claudeOption($command, '--output-format'),
            claudeOption($command, '--setting-sources'),
            claudeOption($command, '--tools'),
            claudeOption($command, '--model'),
        ])->toBe(['json', '', '', 'sonnet']);
        expect($command)->not->toContain('--max-budget-usd');
    });

    it('passes the model, system prompt and json schema from the request', function () {
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest(
            prompt: 'Name a fruit',
            systemPrompt: 'You are a greengrocer.',
            model: 'haiku',
            jsonSchema: ['type' => 'object', 'properties' => ['fruit' => ['type' => 'string']]],
        ));

        $command = $captured->process->command;
        expect([
            claudeOption($command, '--model'),
            claudeOption($command, '--json-schema'),
        ])->toBe(['haiku', '{"type":"object","properties":{"fruit":{"type":"string"}}}']);
        expect(claudeOption($command, '--system-prompt'))->toStartWith('You are a greengrocer.');
    });

    it('tells claude the current date and time in the household\'s timezone', function () {
        config(['app.display_timezone' => 'America/New_York']);
        $this->travelTo('2026-10-10 01:30:00 UTC');
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('What is on tomorrow?'));

        expect(claudeOption($captured->process->command, '--system-prompt'))
            ->toBe("You help the household.\n\nIt is currently Friday 9 October 2026, 21:30 (America/New_York).");
    });

    it('starts only the MCP servers whose tools are allowed and passes their secrets through the environment', function () {
        config(['claude.mcp_servers' => [
            'skylight' => [
                'command' => '/opt/node/bin/node',
                'args' => ['/srv/skylight-mcp/index.js'],
                'env' => ['SKYLIGHT_EMAIL' => 'family@example.com', 'SKYLIGHT_PASSWORD' => 'hunter2', 'SKYLIGHT_FRAME_ID' => null],
            ],
            'pantry' => ['command' => 'pantry-mcp', 'args' => [], 'env' => ['PANTRY_KEY' => 'pantry-secret']],
        ]]);
        $captured = fakeClaudeProcess(claudeFixture('ask-structured.json'));

        app(ClaudeCli::class)->ask(new ClaudeRequest('What\'s on today?', tools: ['WebSearch', 'mcp__skylight__skylight_list_events', 'mcp__skylight__skylight_get_event']));

        $command = $captured->process->command;
        expect([
            claudeOption($command, '--tools'),
            claudeOption($command, '--allowedTools'),
            claudeOption($command, '--mcp-config'),
        ])->toBe([
            'WebSearch',
            'WebSearch,mcp__skylight__skylight_list_events,mcp__skylight__skylight_get_event',
            '{"mcpServers":{"skylight":{"type":"stdio","command":"/opt/node/bin/node","args":["/srv/skylight-mcp/index.js"]}}}',
        ]);
        expect(implode(' ', $command))->not->toContain('hunter2');
        expect($captured->process->environment)
            ->toMatchArray(['SKYLIGHT_EMAIL' => 'family@example.com', 'SKYLIGHT_PASSWORD' => 'hunter2'])
            ->not->toHaveKeys(['SKYLIGHT_FRAME_ID', 'PANTRY_KEY']);
    });

    it('throws and records a failed run when a tool needs an MCP server that is not configured', function () {
        config(['claude.mcp_servers' => []]);
        fakeClaudeProcess(claudeFixture('ask-structured.json'));

        expect(fn () => app(ClaudeCli::class)->ask(new ClaudeRequest('Hello', tools: ['mcp__pantry__list_items'])))
            ->toThrow(ClaudeException::class, 'The MCP server [pantry] is not configured in config/claude.php.');

        expect(ClaudeRun::sole()->status)->toBe(ClaudeRunStatus::Failed);
        Process::assertNothingRan();
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

    it('throws and records a failed run when the process fails without a result', function () {
        fakeClaudeProcess(exitCode: 1, errorOutput: 'No conversation found with session ID: missing');

        expect(fn () => app(ClaudeCli::class)->ask(new ClaudeRequest('Hello', resumeSessionId: 'missing')))
            ->toThrow(ClaudeException::class, 'Claude exited with code 1: No conversation found with session ID: missing');

        expect(ClaudeRun::sole())
            ->status->toBe(ClaudeRunStatus::Failed)
            ->error->toBe('Claude exited with code 1: No conversation found with session ID: missing')
            ->cost_usd->toBe(0.0);
    });

    it('throws and records what was spent when claude returns an error result', function () {
        fakeClaudeProcess(claudeFixture('budget-exceeded.json'), exitCode: 1);

        expect(fn () => app(ClaudeCli::class)->ask(new ClaudeRequest('Hello')))
            ->toThrow(ClaudeException::class, 'Claude returned an error: Reached maximum budget ($0.00001)');

        expect(ClaudeRun::sole())
            ->status->toBe(ClaudeRunStatus::Failed)
            ->error->toBe('Claude returned an error: Reached maximum budget ($0.00001)')
            ->cost_usd->toBe(0.0009);
    });

    it('throws and records a failed run when claude does not finish in time', function () {
        config(['claude.timeout' => 120]);
        Process::preventStrayProcesses();
        Process::fake(['*' => fn () => throw new ProcessTimedOutException(
            new SymfonyTimeoutException(new SymfonyProcess(['claude']), SymfonyTimeoutException::TYPE_GENERAL),
            new FakeProcessResult,
        )]);

        expect(fn () => app(ClaudeCli::class)->ask(new ClaudeRequest('Hello')))
            ->toThrow(ClaudeException::class, 'Claude did not finish within 120 seconds.');

        expect(ClaudeRun::sole())
            ->status->toBe(ClaudeRunStatus::Failed)
            ->error->toBe('Claude did not finish within 120 seconds.');
    });

    it('records a succeeded run with the feature, user, model and usage', function () {
        fakeClaudeProcess(claudeFixture('ask-structured.json'));
        $user = User::factory()->create();

        $result = app(ClaudeCli::class)->ask(new ClaudeRequest('Name a fruit', feature: 'fruit-picker', user: $user, model: 'haiku'));

        expect($result->run->fresh())
            ->user_id->toBe($user->id)
            ->feature->toBe('fruit-picker')
            ->model->toBe('haiku')
            ->status->toBe(ClaudeRunStatus::Succeeded)
            ->session_id->toBe('effd063a-f84b-4d44-be43-a53d44acb7b6')
            ->cost_usd->toBe(0.000851)
            ->input_tokens->toBe(4056)
            ->output_tokens->toBe(80)
            ->duration_ms->toBe(790)
            ->error->toBeNull();
    });
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

    it('records a failed run when the event handler throws mid-stream', function () {
        fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));

        expect(fn () => app(ClaudeCli::class)->stream(new ClaudeRequest('Hello'), fn () => throw new RuntimeException('Browser disconnected')))
            ->toThrow(RuntimeException::class, 'Browser disconnected');

        expect(ClaudeRun::sole())
            ->status->toBe(ClaudeRunStatus::Failed)
            ->error->toBe('Browser disconnected');
    });
});
