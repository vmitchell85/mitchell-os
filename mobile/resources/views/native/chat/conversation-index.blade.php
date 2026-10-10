<native:refreshable @refresh="refresh">
    <native:column class="w-full gap-3 p-4">
        <native:button ref="new-conversation" label="New conversation" icon="add" @press="newConversation" />

        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if (! $loaded)
            <native:activity-indicator />
        @endif

        @forelse ($conversations as $conversation)
            <native:pressable ref="conversation-{{ $conversation['id'] }}" @press="openConversation({{ $conversation['id'] }})">
                <native:column class="w-full gap-1 p-4 bg-theme-surface rounded-xl">
                    <native:text class="text-base font-semibold text-theme-on-surface">{{ $conversation['title'] }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $conversation['updated'] }}</native:text>
                </native:column>
            </native:pressable>
        @empty
            @if ($loaded && $error === '')
                <native:text class="text-theme-on-surface-variant">No conversations yet. Start one!</native:text>
            @endif
        @endforelse
    </native:column>
</native:refreshable>
