<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;

/**
 * @param  array<string, mixed>  $structuredOutput
 */
function titleResult(array $structuredOutput): string
{
    return json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'session_id' => 'f1c1a0de-7d5b-4a39-9a43-2e8a4c6f0b11',
        'total_cost_usd' => 0.0004,
        'result' => json_encode($structuredOutput),
        'structured_output' => $structuredOutput,
    ]);
}

it('titles the conversation from its first message', function () {
    $captured = fakeClaudeProcess(titleResult(['title' => '"Weekend meal plan"']));
    $conversation = Conversation::factory()->create(['title' => 'Can you plan our meals for Sat…']);
    Message::factory()->for($conversation)->create(['content' => 'Can you plan our meals for Saturday and Sunday?']);
    Message::factory()->for($conversation)->create(['content' => 'Make Sunday vegetarian']);

    GenerateConversationTitle::dispatchSync($conversation);

    expect($conversation->fresh()->title)->toBe('Weekend meal plan');
    expect([$captured->process->input, claudeOption($captured->process->command, '--model')])
        ->toBe(['Can you plan our meals for Saturday and Sunday?', 'haiku']);
});

it('keeps the placeholder title when claude returns a blank one', function () {
    fakeClaudeProcess(titleResult(['title' => ' ']));
    $conversation = Conversation::factory()->create(['title' => 'Can you plan our meals for Sat…']);
    Message::factory()->for($conversation)->create();

    GenerateConversationTitle::dispatchSync($conversation);

    expect($conversation->fresh()->title)->toBe('Can you plan our meals for Sat…');
});
