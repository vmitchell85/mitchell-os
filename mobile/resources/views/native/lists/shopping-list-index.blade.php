@use('App\Icons\Android')
@use('App\Icons\Ios')

<native:column class="w-full flex-1">
    @if ($error && ! $showCreate)
        <native:text class="p-4 text-sm text-theme-destructive">{{ $error }}</native:text>
    @endif

    <native:list separator on-refresh="refresh">
        @foreach ($lists as $list)
            <native:list-item
                :headline="$list['name']"
                :supporting="($list['pending_items_count'] ?? 0) === 1 ? '1 item to get' : ($list['pending_items_count'] ?? 0).' items to get'"
                trailingIcon="forward"
                @press="openList({{ $list['id'] }})"
            />
        @endforeach
    </native:list>

    <native:fab :ios-icon="Ios::Plus" :android-icon="Android::Add" a11y-label="New list" @tap="openCreate" />

    <native:modal :visible="$showCreate" @dismiss="closeCreate">
        <native:column class="w-full h-full p-4 gap-4 safe-area">
            <native:text class="text-2xl font-bold text-theme-on-surface">New list</native:text>
            <native:outlined-text-input label="Name" placeholder="Costco, hardware store..." native:model="newName" autocapitalize="words" @submit="createList" />
            @if ($error)
                <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            @endif
            <native:button label="Create list" @press="createList" />
        </native:column>
    </native:modal>
</native:column>
