<?php

use App\NativeComponents\Chat\ConversationShow;
use App\Services\Api\Session;
use App\Support\ChatMarkdown;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function chatMessage(int $id, string $role, string $content, string $at = '2026-10-09T18:30:00.000000Z'): array
{
    return ['id' => $id, 'role' => $role, 'content' => $content, 'created_at' => $at];
}

function chatConversation(array $messages, int $id = 7, string $title = 'Dinner ideas'): array
{
    return ['data' => ['id' => $id, 'title' => $title, 'created_at' => '2026-10-09T18:00:00.000000Z', 'updated_at' => '2026-10-09T18:30:00.000000Z', 'messages' => $messages]];
}

it('lists conversations with Eastern times', function () {
    Http::fake(['*/conversations' => Http::response(['data' => [
        ['id' => 7, 'title' => 'Dinner ideas', 'updated_at' => '2026-10-09T18:30:00.000000Z'],
        ['id' => 3, 'title' => '', 'updated_at' => '2026-10-08T12:00:00.000000Z'],
    ]])]);

    Native::visit('/chat')
        ->assertSee('Dinner ideas')
        ->assertSee('Oct 9, 2:30 PM')
        ->assertSee('New conversation');
});

it('shows an empty state', function () {
    Http::fake(['*/conversations' => Http::response(['data' => []])]);

    Native::visit('/chat')->assertSee('No conversations yet');
});

it('opens a conversation and starts a new one', function () {
    Http::fake(['*/conversations' => Http::response(['data' => [['id' => 7, 'title' => 'Dinner ideas', 'updated_at' => null]]])]);

    Native::visit('/chat')->tap('conversation-7')->assertNavigatedTo('/chat/7');
    Native::visit('/chat')->tap('new-conversation')->assertNavigatedTo('/chat/new');
});

it('shows the sign-in screen when the token is rejected', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    $this->mock(Session::class, function ($mock) {
        $mock->shouldReceive('signOut')->atLeast()->once();
        $mock->shouldReceive('token')->andReturn('bad');
        $mock->shouldReceive('serverUrl')->andReturn('http://localhost');
    });

    Native::visit('/chat')->assertReplacedWith('/sign-in');
});

it('loads the messages of a conversation', function () {
    Http::fake(['*/conversations/7' => Http::response(chatConversation([
        chatMessage(1, 'user', 'What is for dinner?'),
        chatMessage(2, 'assistant', 'Tacos tonight.'),
    ]))]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->assertSee('What is for dinner?')
        ->assertSee('Tacos tonight.')
        ->assertSee('2:30 PM');
});

it('sends a message, shows it right away and then the reply', function () {
    Http::fake([
        '*/conversations/7/messages' => Http::response(chatConversation([
            chatMessage(1, 'user', 'Hi'),
            chatMessage(2, 'assistant', 'Hello there!'),
        ])),
        '*/conversations/7' => Http::response(chatConversation([chatMessage(1, 'user', 'Earlier')])),
    ]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->set('draft', 'Hi')
        ->call('send')
        ->assertSet('draft', '')
        ->assertSet('waiting', true)
        ->assertSee('Hi')
        ->assertSee('Claude is thinking')
        ->firePoll('tick')
        ->assertSet('waiting', false)
        ->assertSee('Hello there!')
        ->assertDontSee('Claude is thinking');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/conversations/7/messages')
        && $request['message'] === 'Hi');
});

it('does not send a blank message', function () {
    Http::fake(['*' => Http::response(chatConversation([]))]);

    Native::test(ConversationShow::class)->set('draft', '   ')->call('send')->assertSet('waiting', false);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('starts a new conversation with its first message', function () {
    Http::fake(['*/conversations' => Http::response(chatConversation([
        chatMessage(1, 'user', 'Plan dinner'),
        chatMessage(2, 'assistant', 'How about pasta?'),
    ]), 201)]);

    Native::visit('/chat/new')
        ->set('draft', 'Plan dinner')
        ->call('send')
        ->firePoll('tick')
        ->assertSet('conversationId', 7)
        ->assertSet('waiting', false)
        ->assertSee('How about pasta?');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/conversations')
        && $request['message'] === 'Plan dinner');
});

it('polls until the reply lands after the request times out', function () {
    Http::fake([
        '*/conversations/7/messages' => fn () => throw new ConnectionException('timed out'),
        '*/conversations/7' => Http::sequence()
            ->push(chatConversation([]))
            ->push(chatConversation([chatMessage(1, 'user', 'Hi')]))
            ->push(chatConversation([chatMessage(1, 'user', 'Hi'), chatMessage(2, 'assistant', 'Sorry for the wait!')])),
    ]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->set('draft', 'Hi')
        ->call('send')
        ->firePoll('tick')
        ->assertSet('waiting', true)
        ->firePoll('tick')
        ->assertSet('waiting', true)
        ->assertDontSee('Sorry for the wait!')
        ->firePoll('tick')
        ->assertSet('waiting', false)
        ->assertSee('Sorry for the wait!');
});

it('finds a new conversation after its request times out', function () {
    Http::fake([
        '*/conversations/7' => Http::response(chatConversation([chatMessage(1, 'user', 'Hi'), chatMessage(2, 'assistant', 'Done')])),
        '*/conversations' => fn (Request $request) => $request->method() === 'POST'
            ? throw new ConnectionException('timed out')
            : Http::response(['data' => [['id' => 7, 'title' => 'Hi', 'updated_at' => null]]]),
    ]);

    Native::visit('/chat/new')
        ->set('draft', 'Hi')
        ->call('send')
        ->firePoll('tick')
        ->assertSet('conversationId', 7)
        ->firePoll('tick')
        ->assertSet('waiting', false)
        ->assertSee('Done');
});

it('offers a retry when Claude fails to answer', function () {
    Http::fake([
        '*/conversations/7/messages' => Http::response(['message' => 'Claude timed out.', 'conversation_id' => 7], 502),
        '*/conversations/7/reply' => Http::response(chatConversation([chatMessage(1, 'user', 'Hi'), chatMessage(2, 'assistant', 'Back again!')])),
        '*/conversations/7' => Http::response(chatConversation([chatMessage(1, 'user', 'Hi')])),
    ]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->set('draft', 'Hi')
        ->call('send')
        ->firePoll('tick')
        ->assertSet('waiting', false)
        ->assertSet('canRetry', true)
        ->assertSee('Claude timed out.')
        ->tap('retry')
        ->firePoll('tick')
        ->assertSet('canRetry', false)
        ->assertSee('Back again!');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/conversations/7/reply'));
});

it('gives the text back when the server rejects the message', function () {
    Http::fake([
        '*/conversations/7/messages' => Http::response(['message' => 'Invalid', 'errors' => ['message' => ['The message field must not be greater than 20000 characters.']]], 422),
        '*/conversations/7' => Http::response(chatConversation([])),
    ]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->set('draft', 'way too long')
        ->call('send')
        ->firePoll('tick')
        ->assertSet('waiting', false)
        ->assertSet('draft', 'way too long')
        ->assertSet('error', 'The message field must not be greater than 20000 characters.');
});

it('does nothing on a tick when no reply is expected', function () {
    Http::fake(['*/conversations/7' => Http::response(chatConversation([chatMessage(1, 'user', 'Hi')]))]);

    Native::test(ConversationShow::class, params: ['id' => 7])->firePoll('tick');

    Http::assertSentCount(1);
});

it('parses markdown blocks and inline styles', function () {
    $blocks = ChatMarkdown::blocks("## Plan\n\nHere is **bold**, *soft* and `code`.\n\n- one\n  - nested\n2. two\n\n```\nraw **text**\n```\n\n---");

    expect(collect($blocks)->pluck('type')->all())->toBe(['heading', 'paragraph', 'bullet', 'bullet', 'numbered', 'code', 'rule'])
        ->and($blocks[0]['level'])->toBe(2)
        ->and($blocks[3]['indent'])->toBe(1)
        ->and($blocks[4]['marker'])->toBe('2.')
        ->and($blocks[5]['text'])->toBe('raw **text**');

    expect(collect($blocks[1]['runs'])->map(fn (array $run) => [$run['text'], $run['bold'], $run['italic'], $run['code']])->all())->toBe([
        ['Here is ', false, false, false],
        ['bold', true, false, false],
        [', ', false, false, false],
        ['soft', false, true, false],
        [' and ', false, false, false],
        ['code', false, false, true],
        ['.', false, false, false],
    ]);
});

it('shows links by their label', function () {
    $runs = ChatMarkdown::runs('See [the recipe](https://example.com/r) now');

    expect($runs[1])->toMatchArray(['text' => 'the recipe', 'link' => true]);
});

it('renders assistant markdown without raw markers and keeps user text plain', function () {
    Http::fake(['*/conversations/7' => Http::response(chatConversation([
        chatMessage(1, 'user', 'Use **my** words'),
        chatMessage(2, 'assistant', "Try **tacos**:\n\n- beef\n- beans"),
    ]))]);

    Native::test(ConversationShow::class, params: ['id' => 7])
        ->assertSee('Use **my** words')
        ->assertSee('tacos')
        ->assertSee('beans')
        ->assertDontSee('**tacos**')
        ->assertDontSee('- beef');
});
