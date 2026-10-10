<native:refreshable @refresh="refresh">
    <native:column class="w-full gap-4 p-4">
        <native:row class="w-full items-center justify-between">
            <native:button label="Previous" icon="back" variant="ghost" size="sm" @press="previousWeek" />
            <native:pressable @press="thisWeek">
                <native:text class="text-base font-semibold text-theme-on-background">{{ $this->weekLabel }}</native:text>
            </native:pressable>
            <native:button label="Next" icon-trailing="forward" variant="ghost" size="sm" @press="nextWeek" />
        </native:row>

        <native:button
            :label="$planning ? 'Claude is planning…' : 'Plan the week'"
            size="lg"
            :loading="$planning"
            :disabled="$planning"
            @press="planWeek"
        />

        @if ($planning)
            <native:column class="w-full gap-2 items-center">
                <native:activity-indicator />
                <native:text class="text-center text-theme-on-surface-variant">Claude is drafting meals for the week. This can take a minute or two.</native:text>
            </native:column>
        @endif

        @if ($status !== '')
            <native:text class="text-theme-primary">{{ $status }}</native:text>
        @endif

        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($this->draftCount > 0)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->draftCount }} {{ $this->draftCount === 1 ? 'suggestion is' : 'suggestions are' }} waiting for approval.</native:text>
        @endif

        @forelse ($this->days as $day)
            <native:column class="w-full gap-2">
                <native:text class="text-lg font-bold text-theme-on-background">{{ $day['label'] }}</native:text>

                @foreach ($day['meals'] as $meal)
                    <native:pressable @navigate="'/meals/' . $meal['id']" class="w-full">
                        <native:row class="w-full items-center gap-3 p-4 rounded-lg bg-theme-surface">
                            <native:column class="flex-1 gap-1">
                                <native:text class="text-xs text-theme-on-surface-variant">{{ $meal['category'] }}</native:text>
                                <native:text class="text-base font-semibold text-theme-on-surface">{{ $meal['name'] }}</native:text>
                            </native:column>
                            @if ($meal['is_draft'])
                                <native:badge label="Draft" variant="accent" />
                            @else
                                <native:badge label="Approved" variant="primary" />
                            @endif
                        </native:row>
                    </native:pressable>
                @endforeach
            </native:column>
        @empty
            @if ($loaded && $error === '')
                <native:text class="w-full text-center text-theme-on-surface-variant">No meals planned for this week yet.</native:text>
            @endif
        @endforelse
    </native:column>
</native:refreshable>
