<native:scroll-view class="w-full flex-1">
    <native:column class="w-full gap-4 p-4">
        @if (! $loaded && $error === '')
            <native:activity-indicator />
        @endif

        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($status !== '')
            <native:text class="text-theme-primary">{{ $status }}</native:text>
        @endif

        @if ($meal !== [] && $editing)
            <native:outlined-text-input label="Name" native:model="name" autocapitalize="sentences" />
            <native:select label="Meal" :options="$categories" native:model="category" />
            <native:outlined-text-input label="Date" supporting="Year-month-day, like 2026-10-14" native:model="date" keyboard="text" autocapitalize="none" />
            <native:outlined-text-input label="Ingredients" supporting="One per line" native:model="ingredients" multiline :min-lines="4" :max-lines="12" />
            <native:outlined-text-input label="Note" native:model="note" multiline :min-lines="2" :max-lines="6" />

            <native:button label="Save" size="lg" :loading="$busy" @press="save" />
            <native:button label="Cancel" variant="ghost" @press="cancelEditing" />
        @elseif ($meal !== [])
            <native:column class="w-full gap-1">
                <native:row class="w-full items-center gap-2">
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $meal['category'] }} · {{ $dateLabel }}</native:text>
                    @if ($meal['is_draft'])
                        <native:badge label="Draft" variant="accent" />
                    @else
                        <native:badge label="Approved" variant="primary" />
                    @endif
                </native:row>
                <native:text class="text-2xl font-bold text-theme-on-background">{{ $meal['name'] }}</native:text>
                @if ($approvedLabel !== '')
                    <native:text class="text-sm text-theme-on-surface-variant">Approved {{ $approvedLabel }}</native:text>
                @endif
            </native:column>

            @if ($ingredientList !== [])
                <native:column class="w-full gap-2 p-4 rounded-lg bg-theme-surface">
                    <native:text class="text-base font-semibold text-theme-on-surface">Ingredients</native:text>
                    @foreach ($ingredientList as $ingredient)
                        <native:text class="text-theme-on-surface">• {{ $ingredient }}</native:text>
                    @endforeach
                </native:column>
            @endif

            @if (! empty($meal['note']))
                <native:column class="w-full gap-1 p-4 rounded-lg bg-theme-surface">
                    <native:text class="text-base font-semibold text-theme-on-surface">Note</native:text>
                    <native:text class="text-theme-on-surface">{{ $meal['note'] }}</native:text>
                </native:column>
            @endif

            @if ($meal['is_draft'])
                <native:button label="Approve" size="lg" icon="check" :disabled="$busy" @press="approve" />

                <native:outlined-text-input
                    label="Anything Claude should change?"
                    placeholder="Optional, like “something lighter”"
                    native:model="regenerateNotes"
                    multiline
                    :min-lines="2"
                />
                <native:button
                    :label="$regenerateRequested || $busy ? 'Claude is thinking…' : 'Suggest something different'"
                    variant="secondary"
                    :loading="$busy"
                    :disabled="$busy"
                    @press="regenerate"
                />
            @endif

            <native:button label="Edit" variant="secondary" :disabled="$busy" @press="startEditing" />
            <native:button label="Remove from plan" variant="destructive" :disabled="$busy" @press="confirmDelete" />
        @endif
    </native:column>
</native:scroll-view>
