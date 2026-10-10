<native:top-bar title="Settings" back />

<native:scroll-view class="w-full flex-1 bg-theme-background">
    <native:column class="w-full gap-6 p-4">
        <native:column class="w-full gap-1 rounded-2xl border border-theme-outline bg-theme-surface p-4">
            <native:text class="text-sm text-theme-on-surface-variant">Signed in as</native:text>
            @if ($loadError !== '')
                <native:text class="text-theme-destructive">{{ $loadError }}</native:text>
            @else
                <native:text class="text-lg font-bold text-theme-on-surface">{{ $userName }}</native:text>
                <native:text class="text-theme-on-surface-variant">{{ $userEmail }}</native:text>
            @endif
        </native:column>

        <native:column class="w-full gap-3">
            <native:outlined-text-input
                label="Server address"
                native:model="serverUrl"
                keyboard="url"
                leading-icon="link"
                @submit="saveServerUrl"
            />
            <native:button label="Save address" variant="secondary" @press="saveServerUrl" />
            @if ($serverMessage !== '')
                <native:text class="text-sm text-theme-on-surface-variant">{{ $serverMessage }}</native:text>
            @endif
        </native:column>

        <native:column class="w-full gap-1 rounded-2xl border border-theme-outline bg-theme-surface p-4">
            <native:text class="text-sm text-theme-on-surface-variant">Tabs</native:text>
            @foreach ($tabOptions as $option)
                <native:toggle
                    ref="tab-{{ $option['key'] }}"
                    label="{{ $option['label'] }}"
                    :value="$option['enabled']"
                    @change="toggleTab('{{ $option['key'] }}')"
                />
            @endforeach
            @if ($tabsMessage !== '')
                <native:text class="text-sm text-theme-destructive">{{ $tabsMessage }}</native:text>
            @endif
        </native:column>

        <native:column class="w-full gap-3">
            <native:button label="Sync with Skylight" icon="sync" :loading="$syncing" @press="syncWithSkylight" />
            @if ($syncMessage !== '')
                <native:text class="{{ $syncFailed ? 'text-theme-destructive' : 'text-theme-on-surface-variant' }}">{{ $syncMessage }}</native:text>
            @endif
        </native:column>

        <native:button label="Sign out" variant="destructive" @press="signOut" />
    </native:column>
</native:scroll-view>
