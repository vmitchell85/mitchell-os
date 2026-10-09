<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\ClaudeRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\MessageRole;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('redirects guests to the login page', function () {
    $response = $this->get(route('chat'));

    $response->assertRedirect(route('login'));
});

it('lists only the signed-in user\'s conversations', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Weekend meal plan']);
    Conversation::factory()->create(['title' => 'Someone else\'s secret']);

    $response = $this->actingAs($user)->get(route('chat'));

    $response->assertOk()
        ->assertSee('Weekend meal plan')
        ->assertDontSee('Someone else\'s secret');
});

it('returns 404 for another user\'s conversation', function () {
    $conversation = Conversation::factory()->create();

    $response = $this->actingAs(User::factory()->create())->get(route('chat', $conversation));

    $response->assertNotFound();
});

it('renders Claude\'s markdown but escapes any html in it', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->fromAssistant()->for($conversation)->create(['content' => "**Bring a coat** <script>alert('hi')</script>"]);

    $response = $this->actingAs($conversation->user)->get(route('chat', $conversation));

    $response->assertOk()
        ->assertSee('<strong>Bring a coat</strong>', false)
        ->assertDontSee("<script>alert('hi')</script>", false);
});

it('starts a conversation from the first message without waiting for claude', function () {
    $user = User::factory()->create();
    fakeClaudeProcess();

    Livewire::actingAs($user)
        ->test('pages::chat')
        ->set('prompt', 'What should we have for dinner?')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('prompt', '');

    $conversation = $user->conversations()->sole();
    expect($conversation->title)->toBe('What should we have for dinner?');
    expect($conversation->messages()->sole())
        ->role->toBe(MessageRole::User)
        ->content->toBe('What should we have for dinner?');
    Process::assertNothingRan();
});

it('rejects an empty message', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::chat')
        ->set('prompt', '')
        ->call('send')
        ->assertHasErrors(['prompt' => 'required']);

    expect($user->conversations()->count())->toBe(0);
});

it('saves claude\'s reply, remembers the session and queues a title on the first reply', function () {
    Queue::fake([GenerateConversationTitle::class]);
    fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['content' => 'What is the top headline?']);

    Livewire::actingAs($conversation->user)
        ->test('pages::chat', ['conversation' => $conversation])
        ->call('reply')
        ->assertSet('error', null)
        ->assertSee('The top story is a by-election result.');

    $reply = $conversation->messages()->where('role', MessageRole::Assistant)->sole();
    expect($reply)
        ->content->toBe('The top story is a by-election result.')
        ->claude_run_id->toBe(ClaudeRun::sole()->id);
    expect($conversation->fresh()->claude_session_id)->toBe('539a1777-5933-47e9-832a-fa0e4010c2d3');
    Queue::assertPushed(GenerateConversationTitle::class, fn (GenerateConversationTitle $job) => $job->conversation->is($conversation));
});

it('resumes the conversation\'s claude session with web search and keeps its title', function () {
    Queue::fake([GenerateConversationTitle::class]);
    $captured = fakeClaudeProcess(claudeFixture('stream-web-search.jsonl'));
    $conversation = Conversation::factory()->withSession()->create();
    Message::factory()->for($conversation)->create(['content' => 'And the weather?']);

    Livewire::actingAs($conversation->user)
        ->test('pages::chat', ['conversation' => $conversation])
        ->call('reply');

    $command = $captured->process->command;
    expect([
        claudeOption($command, '--resume'),
        claudeOption($command, '--tools'),
        $captured->process->input,
    ])->toBe([$conversation->claude_session_id, 'WebSearch', 'And the weather?']);
    Queue::assertNotPushed(GenerateConversationTitle::class);
});

it('shows the error and saves no reply when claude fails', function () {
    Exceptions::fake();
    fakeClaudeProcess(exitCode: 1, errorOutput: 'Not logged in');
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();

    Livewire::actingAs($conversation->user)
        ->test('pages::chat', ['conversation' => $conversation])
        ->call('reply')
        ->assertSet('error', 'Claude exited with code 1: Not logged in')
        ->assertSee('Try again');

    expect($conversation->messages()->where('role', MessageRole::Assistant)->count())->toBe(0);
    Exceptions::assertReported(ClaudeException::class);
});

it('does not call claude when the latest message is already answered', function () {
    fakeClaudeProcess();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->fromAssistant()->for($conversation)->create();

    Livewire::actingAs($conversation->user)
        ->test('pages::chat', ['conversation' => $conversation])
        ->call('reply');

    Process::assertNothingRan();
});
