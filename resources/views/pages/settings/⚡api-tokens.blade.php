<?php

use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    public string $tokenName = '';

    /**
     * The token just created, shown once so it can be copied into the app.
     */
    #[Locked]
    public ?string $plainTextToken = null;

    public function createToken(): void
    {
        $validated = $this->validate([
            'tokenName' => ['required', 'string', 'max:100'],
        ]);

        $this->plainTextToken = Auth::user()->createToken($validated['tokenName'])->plainTextToken;

        $this->reset('tokenName');
    }

    public function revoke(int $tokenId): void
    {
        Auth::user()->tokens()->whereKey($tokenId)->delete();

        Flux::toast(text: __('Token revoked.'));
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return Auth::user()->tokens()->latest('id')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('API tokens') }}</flux:heading>

    <x-pages::settings.layout :heading="__('API tokens')" :subheading="__('Tokens let your own apps use the household API as you')">
        <form wire:submit="createToken" class="my-6 flex items-end gap-2">
            <flux:input wire:model="tokenName" :label="__('Token name')" :placeholder="__('e.g. My phone')" class="flex-1" />

            <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
        </form>

        @if ($plainTextToken)
            <flux:callout variant="success" icon="key" class="mb-6" :heading="__('Copy your new token now')">
                <flux:callout.text>{{ __('It won\'t be shown again. Send it as a Bearer token in the Authorization header.') }}</flux:callout.text>

                <flux:input :value="$plainTextToken" readonly copyable class="mt-2 font-mono" />
            </flux:callout>
        @endif

        <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-4 py-3">
                    <div class="min-w-0">
                        <flux:text class="truncate font-medium text-zinc-900 dark:text-white">{{ $token->name }}</flux:text>
                        <flux:text size="sm">
                            {{ __('Created :date', ['date' => $token->created_at?->toFormattedDayDateString()]) }}
                            ·
                            {{ $token->last_used_at ? __('Last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('Never used') }}
                        </flux:text>
                    </div>

                    <flux:button size="sm" variant="danger" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke this token? Apps using it will stop working.') }}">
                        {{ __('Revoke') }}
                    </flux:button>
                </div>
            @empty
                <flux:text>{{ __('No tokens yet.') }}</flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>
</section>
