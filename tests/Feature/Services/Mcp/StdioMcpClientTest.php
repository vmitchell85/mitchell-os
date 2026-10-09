<?php

use App\Services\Mcp\McpException;
use App\Services\Mcp\StdioMcpClient;

function fakeMcpServer(array $environment = [], int $requestTimeoutSeconds = 10): StdioMcpClient
{
    return new StdioMcpClient(
        command: [PHP_BINARY, base_path('tests/Fixtures/mcp/fake-server.php')],
        environment: $environment,
        requestTimeoutSeconds: $requestTimeoutSeconds,
    );
}

it('returns a tool\'s JSON text content decoded, skipping notifications and stderr noise', function () {
    $client = fakeMcpServer();

    $result = $client->callTool('echo', ['listId' => '9002', 'label' => 'Milk']);

    expect($result)->toBe(['listId' => '9002', 'label' => 'Milk']);
});

it('sends empty arguments as a JSON object', function () {
    $client = fakeMcpServer();

    $result = $client->callTool('echo');

    expect($result)->toBe([]);
});

it('returns text content that is not JSON as a string', function () {
    $client = fakeMcpServer();

    expect($client->callTool('plain'))->toBe('just some words');
});

it('prefers structured content over the text content', function () {
    $client = fakeMcpServer();

    expect($client->callTool('structured'))->toBe(['answer' => 42]);
});

it('throws with the tool\'s message when the tool reports an error', function () {
    fakeMcpServer()->callTool('fail');
})->throws(McpException::class, 'The [fail] tool failed: Skylight error 403');

it('throws instead of waiting when the server rejects a request without attributing the error', function () {
    fakeMcpServer()->callTool('reject');
})->throws(McpException::class, 'MCP [tools/call] failed: Invalid request');

it('passes its environment to the server', function () {
    $client = fakeMcpServer(['FAKE_TOKEN' => 'from-the-environment']);

    expect($client->callTool('env'))->toBe(['token' => 'from-the-environment']);
});

it('reuses one server process across calls', function () {
    $client = fakeMcpServer();

    $first = $client->callTool('pid');
    $second = $client->callTool('pid');

    expect($second)->toBe($first);
});

it('throws with the server\'s error output when it exits mid-call', function () {
    fakeMcpServer()->callTool('crash');
})->throws(McpException::class, 'The MCP server exited during [tools/call]: fake server starting'."\n".'fatal: token cache is not writable');

it('gives up when the server does not answer in time', function () {
    fakeMcpServer(requestTimeoutSeconds: 1)->callTool('hang');
})->throws(McpException::class, 'The MCP server did not answer [tools/call] within 1 seconds.');
