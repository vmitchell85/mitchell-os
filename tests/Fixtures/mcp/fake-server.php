<?php

/*
 * A minimal stdio MCP server for testing StdioMcpClient. It answers
 * newline-delimited JSON-RPC and mixes in the noise a real server can
 * produce: log lines on stderr and notifications on stdout.
 */

fwrite(STDERR, "fake server starting\n");

$respond = function (array $message): void {
    fwrite(STDOUT, json_encode($message)."\n");
};

$text = fn (string $text, bool $isError = false): array => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    $id = $message['id'] ?? null;

    if (str_contains($line, '"params":[]')) {
        $respond(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request: params must be an object']]);

        continue;
    }

    $respond(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'handling '.$message['method']]]);

    match ($message['method']) {
        'initialize' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => $message['params']['protocolVersion'],
            'capabilities' => ['tools' => new stdClass],
            'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
        ]]),
        'notifications/initialized' => null,
        'tools/call' => (function () use ($message, $id, $respond, $text): void {
            $arguments = $message['params']['arguments'];

            match ($message['params']['name']) {
                'echo' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $text(json_encode($arguments))]),
                'plain' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $text('just some words')]),
                'structured' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => [...$text('{"ignored":true}'), 'structuredContent' => ['answer' => 42]]]),
                'fail' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $text('Skylight error 403', isError: true)]),
                'env' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $text(json_encode(['token' => getenv('FAKE_TOKEN')]))]),
                'pid' => $respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $text((string) getmypid())]),
                'reject' => $respond(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']]),
                'hang' => sleep(30),
                'crash' => (function (): void {
                    fwrite(STDERR, "fatal: token cache is not writable\n");
                    exit(1);
                })(),
            };
        })(),
        default => $respond(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]),
    };
}
