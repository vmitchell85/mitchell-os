<native:scroll-view class="w-full flex-1 bg-theme-background">
    <native:column class="w-full gap-4 p-6">
        <native:column class="w-full gap-1 pt-8 pb-2">
            <native:text class="text-3xl font-bold text-theme-on-background">Mitchell OS</native:text>
            <native:text class="text-theme-on-surface-variant">Sign in with your household account.</native:text>
        </native:column>

        <native:outlined-text-input
            label="Server address"
            placeholder="http://10.204.201.51:8000"
            native:model="serverUrl"
            keyboard="url"
            leading-icon="link"
        />
        <native:outlined-text-input
            label="Email"
            placeholder="you@example.com"
            native:model="email"
            keyboard="email"
            leading-icon="email"
        />
        <native:outlined-text-input
            label="Password"
            native:model="password"
            secure
            leading-icon="lock"
            @submit="signIn"
        />

        @if ($needsCode)
            <native:outlined-text-input
                label="Two-factor code"
                supporting="From your authenticator app, or a recovery code if you have lost it."
                native:model="code"
                keyboard="number"
                leading-icon="lock"
                @submit="signIn"
            />
        @else
            <native:button label="I use a two-factor code" variant="ghost" size="sm" @press="showCodeField" />
        @endif

        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        <native:button label="Sign in" size="lg" :loading="$loading" @press="signIn" />
    </native:column>
</native:scroll-view>
