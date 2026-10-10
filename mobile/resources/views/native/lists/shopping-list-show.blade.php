@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="w-full flex-1">
    @if ($error && ! $showBuilder)
        <native:text class="p-4 text-sm text-theme-destructive">{{ $error }}</native:text>
    @endif

    <native:row class="w-full gap-2 px-4 pt-2 items-center">
        <native:outlined-text-input class="flex-1" placeholder="Add an item" native:model="newItem" @submit="addItem" keep-focus-on-submit />
        <native:button label="Add" @press="addItem" />
    </native:row>

    <native:list separator on-refresh="refresh">
        @foreach ($items as $item)
            <native:list-item
                :headline="$item['name']"
                :supporting="$item['section']"
                :leadingCheckbox="$item['completed']"
                on-leading-change="toggleItem({{ $item['id'] }})"
                on-swipe-delete="deleteItem({{ $item['id'] }})"
                @press="toggleItem({{ $item['id'] }})"
            />
        @endforeach
    </native:list>

    <native:fab label="Build from meals" :ios-icon="Ios::ForkKnife" :android-icon="Android::Restaurant" @tap="openBuilder" />

    <native:modal :visible="$showBuilder" @dismiss="closeBuilder">
        <native:column class="w-full h-full p-4 gap-4 safe-area">
            <native:text class="text-2xl font-bold text-theme-on-surface">Build from meals</native:text>

            @if (! $hasProposals)
                <native:text class="text-base text-theme-on-surface-variant">Pick the dates and Claude will work out what to buy for the approved meals. Nothing is added until you confirm.</native:text>
                <native:outlined-text-input label="From (YYYY-MM-DD)" native:model="from" />
                <native:outlined-text-input label="To (YYYY-MM-DD)" native:model="to" />
                @if ($error)
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                @endif
                <native:button label="Find items" @press="buildFromMeals" />
            @else
                @if ($proposals === [])
                    <native:text class="text-base text-theme-on-surface-variant">Nothing new to buy for those dates.</native:text>
                @else
                    <native:list separator>
                        @foreach ($proposals as $index => $proposal)
                            <native:list-item
                                :headline="$proposal['name']"
                                :supporting="implode(', ', $proposal['meals'] ?? [])"
                                :leadingCheckbox="in_array($index, $selectedProposals, true)"
                                on-leading-change="toggleProposal({{ $index }})"
                                @press="toggleProposal({{ $index }})"
                            />
                        @endforeach
                    </native:list>
                    @if ($error)
                        <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                    @endif
                    <native:button label="Add selected" @press="addSelectedProposals" />
                @endif
            @endif
        </native:column>
    </native:modal>
</native:column>
