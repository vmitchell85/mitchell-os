<native:scroll-view fill>
    <native:column class="w-full gap-4 p-4">
        @if ($error)
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        @endif

        <native:outlined-text-input label="Name" native:model="name" autocapitalize="words" />

        <native:outlined-text-input
            label="Ingredients"
            supporting="One per line"
            native:model="ingredients"
            multiline
            :min-lines="4"
        />

        <native:outlined-text-input
            label="Steps"
            supporting="One per line"
            native:model="steps"
            multiline
            :min-lines="4"
        />

        <native:outlined-text-input label="Servings" native:model="servings" />
        <native:outlined-text-input label="Total minutes" native:model="totalMinutes" keyboard="number" />
        <native:outlined-text-input label="Notes" native:model="notes" multiline :min-lines="2" />
        <native:outlined-text-input label="Source link" native:model="sourceUrl" keyboard="url" />

        <native:button label="Save" :loading="$saving" @press="save" />
    </native:column>
</native:scroll-view>
