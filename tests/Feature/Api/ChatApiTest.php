<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Claude\MessageRole;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake([GenerateConversationTitle::class]);
});

it('rejects requests without a token', function () {
    $this->getJson(route('api.v1.conversations.index'))->assertUnauthorized();
});

it('lists only the token owner\'s conversations', function () {
    $user = Sanctum::actingAs(User::factory()->create());
    Conversation::factory()->for($user)->create(['title' => 'Weekend plans']);
    Conversation::factory()->create(['title' => 'Someone else\'s secret']);

    $response = $this->getJson(route('api.v1.conversations.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Weekend plans');
});

it('returns 404 for another user\'s conversation', function () {
    Sanctum::actingAs(User::factory()->create());
    $conversation = Conversation::factory()->create();

    $this->getJson(route('api.v1.conversations.show', $conversation))->assertNotFound();
    $this->postJson(route('api.v1.conversations.messages.store', $conversation), ['message' => 'Hi'])->assertNotFound();
    $this->postJson(route('api.v1.conversations.reply', $conversation))->assertNotFound();
});

it('starts a conversation and answers it as the token owner, with the household tools', function () {
    $user = Sanctum::actingAs(User::factory()->create());
    $captured = fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));

    $response = $this->postJson(route('api.v1.conversations.store'), ['message' => 'What is the top headline?']);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'What is the top headline?')
        ->assertJsonPath('data.messages.0.role', 'user')
        ->assertJsonPath('data.messages.1.role', 'assistant')
        ->assertJsonPath('data.messages.1.content', 'The top story is a by-election result.');
    expect($user->conversations()->sole()->claude_session_id)->toBe('539a1777-5933-47e9-832a-fa0e4010c2d3');
    expect($captured->process->environment['HOUSEHOLD_USER_ID'])->toBe((string) $user->id);
    Queue::assertPushed(GenerateConversationTitle::class);
});

it('continues a conversation by resuming its claude session', function () {
    $captured = fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));
    $conversation = Conversation::factory()->withSession()->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->fromAssistant()->for($conversation)->create();
    Sanctum::actingAs($conversation->user);

    $response = $this->postJson(route('api.v1.conversations.messages.store', $conversation), ['message' => 'And the weather?']);

    $response->assertOk()->assertJsonCount(4, 'data.messages');
    expect(claudeOption($captured->process->command, '--resume'))->toBe($conversation->claude_session_id);
    expect($captured->process->input)->toBe('And the weather?');
});

it('streams the reply as server-sent events', function () {
    fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));
    $user = Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson(route('api.v1.conversations.store'), ['message' => 'What is the top headline?', 'stream' => true]);

    $response->assertCreated()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    $content = $response->streamedContent();
    $conversation = $user->conversations()->sole();
    expect($content)
        ->toContain("event: conversation\ndata: {\"id\":{$conversation->id},")
        ->toContain("event: status\ndata: {\"text\":\"Searching the web…\"}")
        ->toContain("event: text\ndata: {\"text\":\"The top story is \"}")
        ->toContain("event: done\ndata: {\"message\":{\"id\":");
    expect($conversation->messages()->where('role', MessageRole::Assistant)->sole()->content)->toBe('The top story is a by-election result.');
});

it('keeps the question and reports the failure when claude fails, so it can be retried', function () {
    Exceptions::fake();
    fakeClaudeProcess(exitCode: 1, errorOutput: 'Not logged in');
    $user = Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson(route('api.v1.conversations.store'), ['message' => 'Hello?']);

    $conversation = $user->conversations()->sole();
    $response->assertStatus(502)->assertExactJson([
        'message' => 'Claude exited with code 1: Not logged in',
        'conversation_id' => $conversation->id,
    ]);
    expect($conversation->messages()->sole()->role)->toBe(MessageRole::User);

    fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));

    $this->postJson(route('api.v1.conversations.reply', $conversation))
        ->assertOk()
        ->assertJsonCount(2, 'data.messages');
});

it('refuses a new message while the last one is unanswered', function () {
    fakeClaudeProcess();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();
    Sanctum::actingAs($conversation->user);

    $this->postJson(route('api.v1.conversations.messages.store', $conversation), ['message' => 'Hello?'])->assertConflict();

    expect($conversation->messages()->count())->toBe(1);
    Process::assertNothingRan();
});

it('rejects an empty message', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.conversations.store'), ['message' => ''])->assertJsonValidationErrors('message');
});
