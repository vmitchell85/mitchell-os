@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:column class="w-full flex-1">
    <native:column class="w-full gap-3 p-4">
        <native:filled-text-input
            placeholder="Search recipes"
            native:model.debounce.300ms="search"
            leading-icon="search"
        />

        <native:outlined-text-input
            label="Import from a link"
            placeholder="https://"
            native:model="importUrl"
            keyboard="url"
            @submit="importRecipe"
        />

        <native:row class="w-full gap-3">
            <native:button label="Import" variant="secondary" :loading="$importing" @press="importRecipe" />
            <native:button label="Add recipe" :ios-icon="Ios::Plus" :android-icon="Android::Add" @press="addRecipe" />
        </native:row>

        @if ($error)
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        @endif
    </native:column>

    @if (count($recipes) === 0)
        <native:column class="w-full flex-1 items-center justify-center p-6">
            <native:text class="text-center text-theme-on-surface-variant">
                {{ trim($search) === '' ? 'No recipes yet. Import one from a link or add your own.' : 'No recipes match that search.' }}
            </native:text>
        </native:column>
    @else
        <native:list separator on-refresh="loadRecipes">
            @foreach ($recipes as $recipe)
                <native:list-item
                    :headline="$recipe['name']"
                    :supporting="collect([
                        $recipe['servings'] ? 'Serves '.$recipe['servings'] : null,
                        $recipe['total_minutes'] ? $recipe['total_minutes'].' min' : null,
                    ])->filter()->implode(' · ')"
                    trailingIcon="forward"
                    @press="openRecipe({{ $recipe['id'] }})"
                />
            @endforeach
        </native:list>
    @endif
</native:column>
