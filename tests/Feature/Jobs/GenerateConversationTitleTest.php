<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;

it('titles the conversation from its first message', function () {
    $captured = fakeClaudeProcess(claudeStructuredResult(['title' => '"Weekend meal plan"']));
    $conversation = Conversation::factory()->create(['title' => 'Can you plan our meals for Sat…']);
    Message::factory()->for($conversation)->create(['content' => 'Can you plan our meals for Saturday and Sunday?']);
    Message::factory()->for($conversation)->create(['content' => 'Make Sunday vegetarian']);

    GenerateConversationTitle::dispatchSync($conversation);

    expect($conversation->fresh()->title)->toBe('Weekend meal plan');
    expect([$captured->process->input, claudeOption($captured->process->command, '--model')])
        ->toBe(['Can you plan our meals for Saturday and Sunday?', 'haiku']);
});

it('keeps the placeholder title when claude returns a blank one', function () {
    fakeClaudeProcess(claudeStructuredResult(['title' => ' ']));
    $conversation = Conversation::factory()->create(['title' => 'Can you plan our meals for Sat…']);
    Message::factory()->for($conversation)->create();

    GenerateConversationTitle::dispatchSync($conversation);

    expect($conversation->fresh()->title)->toBe('Can you plan our meals for Sat…');
});
