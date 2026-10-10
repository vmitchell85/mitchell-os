@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:scroll-view fill>
    <native:column class="w-full gap-4 p-4">
        @if ($error)
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($recipe)
            <native:text class="text-2xl font-bold">{{ $recipe['name'] }}</native:text>

            @if ($recipe['servings'] || $recipe['total_minutes'])
                <native:text class="text-theme-on-surface-variant">
                    {{ collect([
                        $recipe['servings'] ? 'Serves '.$recipe['servings'] : null,
                        $recipe['total_minutes'] ? $recipe['total_minutes'].' min' : null,
                    ])->filter()->implode(' · ') }}
                </native:text>
            @endif

            <native:row class="w-full gap-3">
                <native:button label="Edit" variant="secondary" :ios-icon="Ios::Pencil" :android-icon="Android::Edit" @press="edit" />
                <native:button label="Share" variant="secondary" :ios-icon="Ios::SquareAndArrowUp" :android-icon="Android::Share" @press="share" />
                <native:button label="Delete" variant="destructive" :ios-icon="Ios::Trash" :android-icon="Android::Delete" @press="confirmDelete" />
            </native:row>

            @if ($recipe['ingredients'])
                <native:text class="text-lg font-semibold">Ingredients</native:text>
                <native:column class="w-full gap-2">
                    @foreach ($recipe['ingredients'] as $ingredient)
                        <native:text>{{ $ingredient }}</native:text>
                    @endforeach
                </native:column>
            @endif

            @if ($recipe['steps'])
                <native:text class="text-lg font-semibold">Steps</native:text>
                <native:column class="w-full gap-3">
                    @foreach ($recipe['steps'] as $number => $step)
                        <native:text>{{ $number + 1 }}. {{ $step }}</native:text>
                    @endforeach
                </native:column>
            @endif

            @if ($recipe['notes'])
                <native:text class="text-lg font-semibold">Notes</native:text>
                <native:text>{{ $recipe['notes'] }}</native:text>
            @endif

            @if ($recipe['source_url'])
                <native:text class="text-sm text-theme-on-surface-variant">{{ $recipe['source_url'] }}</native:text>
            @endif
        @endif
    </native:column>
</native:scroll-view>
