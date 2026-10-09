<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeException;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\MessageRole;
use App\Services\Claude\StreamEvent;
use App\Services\Claude\StreamEventType;
use App\Services\Skylight\SkylightTools;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Chat')] class extends Component {
    #[Locked]
    public ?Conversation $conversation = null;

    public string $prompt = '';

    public ?string $error = null;

    /**
     * Mount the component.
     */
    public function mount(?Conversation $conversation = null): void
    {
        if ($conversation !== null) {
            $this->authorize('view', $conversation);
        }

        $this->conversation = $conversation;
    }

    /**
     * Save the user's message, then ask Claude for a reply in a second request so the message shows straight away.
     */
    public function send(): void
    {
        $validated = $this->validate([
            'prompt' => ['required', 'string', 'max:20000'],
        ]);

        if ($this->conversation === null) {
            $this->conversation = Auth::user()->conversations()->create([
                'title' => Str::limit($validated['prompt'], 60),
            ]);

            $this->js('history.replaceState(history.state, "", '.json_encode(route('chat', $this->conversation)).')');
        }

        $this->conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $validated['prompt'],
        ]);

        $this->reset('prompt', 'error');

        $this->js('$wire.reply()');
    }

    /**
     * Stream Claude's answer to the latest unanswered message.
     */
    public function reply(ClaudeCli $claude): void
    {
        $question = $this->unansweredMessage();

        if ($question === null) {
            return;
        }

        $this->error = null;

        try {
            $result = $claude->stream(new ClaudeRequest(
                prompt: $question->content,
                feature: 'chat',
                user: Auth::user(),
                systemPrompt: $this->systemPrompt(),
                tools: ['WebSearch', ...SkylightTools::Read, ...SkylightTools::Add],
                resumeSessionId: $this->conversation->claude_session_id,
                persistSession: true,
            ), function (StreamEvent $event): void {
                match ($event->type) {
                    StreamEventType::TextDelta => $this->stream(e($event->text), to: 'reply'),
                    StreamEventType::ToolUse => $this->stream(e($this->toolStatus($event->toolName)), replace: true, to: 'status'),
                };
            });
        } catch (ClaudeException $exception) {
            report($exception);

            $this->error = $exception->getMessage();

            return;
        }

        $isFirstReply = $this->conversation->claude_session_id === null;

        $this->conversation->update(['claude_session_id' => $result->sessionId]);

        $this->conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $result->text,
            'claude_run_id' => $result->run->id,
        ]);

        if ($isFirstReply) {
            GenerateConversationTitle::dispatch($this->conversation);
        }
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation?->messages()->oldest('id')->get() ?? new Collection;
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return Auth::user()->conversations()->latest('updated_at')->limit(50)->get(['id', 'title', 'updated_at']);
    }

    protected function unansweredMessage(): ?Message
    {
        $latest = $this->conversation?->messages()->latest('id')->first();

        return $latest?->role === MessageRole::User ? $latest : null;
    }

    protected function systemPrompt(): string
    {
        return config('claude.system_prompt').' You are chatting with '.Auth::user()->name.'.'
            .' The family uses a Skylight calendar: you can look up its events, lists, chores and meal plan, and add events, list items and planned meals.'
            .' You cannot change or delete anything that is already in Skylight, so if asked to, explain that it needs doing in the Skylight app.';
    }

    protected function toolStatus(?string $toolName): string
    {
        return match (true) {
            $toolName === 'WebSearch' => __('Searching the web…'),
            $toolName === 'WebFetch' => __('Reading a web page…'),
            in_array($toolName, SkylightTools::Add, true) => __('Updating Skylight…'),
            str_starts_with((string) $toolName, 'mcp__skylight__') => __('Checking Skylight…'),
            default => __('Using :tool…', ['tool' => $toolName]),
        };
    }
}; ?>

<div class="flex h-[calc(100dvh-4rem)] gap-6 lg:h-[calc(100dvh-3rem)]">
    <aside class="hidden w-64 shrink-0 flex-col gap-2 overflow-y-auto lg:flex">
        <flux:button :href="route('chat')" icon="plus" variant="filled" wire:navigate>
            {{ __('New chat') }}
        </flux:button>

        <nav class="flex flex-col gap-1">
            @foreach ($this->conversations as $item)
                <a
                    wire:key="conversation-{{ $item->id }}"
                    href="{{ route('chat', $item) }}"
                    wire:navigate
                    @class([
                        'truncate rounded-lg px-3 py-2 text-sm',
                        'bg-zinc-200 font-medium text-zinc-900 dark:bg-zinc-700 dark:text-white' => $item->is($conversation),
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' => ! $item->is($conversation),
                    ])
                >
                    {{ $item->title ?? __('Untitled chat') }}
                </a>
            @endforeach
        </nav>
    </aside>

    <section class="flex min-w-0 flex-1 flex-col">
        <div class="mb-4 flex items-center gap-2 lg:hidden">
            <flux:dropdown>
                <flux:button icon:trailing="chevron-down" size="sm">{{ __('Chats') }}</flux:button>

                <flux:menu>
                    @foreach ($this->conversations as $item)
                        <flux:menu.item wire:key="menu-conversation-{{ $item->id }}" :href="route('chat', $item)" wire:navigate>
                            {{ Str::limit($item->title ?? __('Untitled chat'), 40) }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:button :href="route('chat')" icon="plus" size="sm" wire:navigate>{{ __('New chat') }}</flux:button>
        </div>

        <div
            class="flex-1 overflow-y-auto"
            x-data="{ stick() { this.$el.scrollTop = this.$el.scrollHeight } }"
            x-init="stick(); new MutationObserver(() => stick()).observe($el, { childList: true, subtree: true, characterData: true })"
        >
            <div class="mx-auto flex max-w-3xl flex-col gap-6 pb-6">
                @forelse ($this->messages as $message)
                    @if ($message->role === MessageRole::User)
                        <div wire:key="message-{{ $message->id }}" class="ms-auto max-w-[85%] whitespace-pre-wrap rounded-2xl bg-zinc-100 px-4 py-3 dark:bg-zinc-700">{{ $message->content }}</div>
                    @else
                        <div wire:key="message-{{ $message->id }}" class="chat-markdown">
                            {!! Str::markdown($message->content, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @endif
                @empty
                    <div class="flex flex-col items-center gap-2 py-24 text-center">
                        <flux:heading size="xl">{{ __('What can I help with?') }}</flux:heading>
                        <flux:text>{{ __('Claude can search the web and check or add to the family\'s Skylight calendar, lists and meal plan.') }}</flux:text>
                    </div>
                @endforelse

                <div wire:loading.flex wire:target="reply" class="flex-col gap-2">
                    <flux:text class="animate-pulse" wire:stream.replace="status">{{ __('Thinking…') }}</flux:text>
                    <div class="whitespace-pre-wrap" wire:stream="reply"></div>
                </div>

                @if ($error)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Claude couldn\'t answer')">
                        <flux:callout.text>{{ $error }}</flux:callout.text>

                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="reply">{{ __('Try again') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif
            </div>
        </div>

        <form wire:submit="send" class="mx-auto w-full max-w-3xl">
            <div class="flex items-end gap-2">
                <flux:textarea
                    wire:model="prompt"
                    rows="auto"
                    resize="none"
                    :placeholder="__('Message Claude…')"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                    autofocus
                />

                <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send,reply" :aria-label="__('Send')" />
            </div>

            <flux:error name="prompt" />
        </form>
    </section>
</div>
