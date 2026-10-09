<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

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

/**
 * @return array<int|string, mixed>
 */
function skylightFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/skylight/{$name}.json")), true);
}

/**
 * A successful `claude -p --output-format json` result carrying structured output.
 *
 * @param  array<string, mixed>  $structuredOutput
 */
function claudeStructuredResult(array $structuredOutput): string
{
    return json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'session_id' => 'f1c1a0de-7d5b-4a39-9a43-2e8a4c6f0b11',
        'total_cost_usd' => 0.0123,
        'result' => json_encode($structuredOutput),
        'structured_output' => $structuredOutput,
    ]);
}
