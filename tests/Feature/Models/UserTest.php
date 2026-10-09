<?php

use App\Models\ClaudeRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('deletes the user\'s conversations but keeps their claude runs for spend history', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $run = ClaudeRun::factory()->for($user)->create();
    $message = Message::factory()->fromAssistant()->for($conversation)->for($run)->create();

    $user->delete();

    $this->assertModelMissing($conversation);
    $this->assertModelMissing($message);
    expect($run->fresh()->user_id)->toBeNull();
});
