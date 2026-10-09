<?php

use App\Actions\Chat\ReplyProgress;
use App\Actions\Chat\ReplyToConversation;
use App\Jobs\AnswerChatMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\MessageRole;
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
     * Save the user's message and have Claude answer it in the background,
     * so the reply carries on if they leave the page.
     */
    public function send(): void
    {
        $validated = $this->validate([
            'prompt' => ['required', 'string', 'max:20000'],
        ]);

        if ($this->isReplying()) {
            $this->addError('prompt', __('Wait for Claude to finish replying.'));

            return;
        }

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

        $this->reset('prompt');

        $this->answer();
    }

    /**
     * Ask Claude again to answer the latest message, after a failed or interrupted reply.
     */
    public function retry(ReplyToConversation $replyToConversation): void
    {
        if ($this->conversation === null || $this->isReplying() || $replyToConversation->unansweredMessage($this->conversation) === null) {
            return;
        }

        $this->answer();
    }

    /**
     * What Claude has written so far, or why it failed.
     *
     * @return array{status: string|null, text: string, error: string|null}|null
     */
    #[Computed]
    public function progress(): ?array
    {
        return $this->conversation !== null ? ReplyProgress::for($this->conversation)->get() : null;
    }

    /**
     * Whether the latest message has no reply and none is being written,
     * for example because an earlier reply was cut off.
     */
    #[Computed]
    public function isUnanswered(): bool
    {
        return $this->progress === null && $this->messages->last()?->role === MessageRole::User;
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

    protected function answer(): void
    {
        ReplyProgress::for($this->conversation)->begin();

        AnswerChatMessage::dispatch($this->conversation);
    }

    protected function isReplying(): bool
    {
        return $this->conversation !== null && ReplyProgress::for($this->conversation)->isReplying();
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
                        <flux:text>{{ __('Claude can search the web, check the calendar, meal plan and shopping lists, add to them, and suggest meals.') }}</flux:text>
                    </div>
                @endforelse

                @if ($this->progress && $this->progress['error'] === null)
                    <div wire:poll.750ms class="flex flex-col gap-2">
                        <flux:text class="animate-pulse">{{ $this->progress['status'] ?? __('Thinking…') }}</flux:text>
                        <div class="whitespace-pre-wrap">{{ $this->progress['text'] }}</div>
                    </div>
                @elseif ($this->progress)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Claude couldn\'t answer')">
                        <flux:callout.text>{{ $this->progress['error'] }}</flux:callout.text>

                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="retry">{{ __('Try again') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @elseif ($this->isUnanswered)
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No reply yet')">
                        <flux:callout.text>{{ __('Claude\'s reply to this message was interrupted.') }}</flux:callout.text>

                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="retry">{{ __('Try again') }}</flux:button>
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

                <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send,retry" :aria-label="__('Send')" />
            </div>

            <flux:error name="prompt" />
        </form>
    </section>
</div>
