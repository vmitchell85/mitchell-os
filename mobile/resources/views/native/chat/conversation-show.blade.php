<native:column class="w-full flex-1">
    <native:scroll-view class="w-full flex-1" scroll-anchor="bottom">
        <native:column class="w-full gap-3 p-4">
            @if ($error !== '')
                <native:text class="text-theme-destructive">{{ $error }}</native:text>
            @endif

            @if ($canRetry)
                <native:button ref="retry" label="Try again" variant="secondary" size="sm" @press="retry" />
            @endif

            @if ($loaded && count($messages) === 0 && ! $waiting)
                <native:text class="text-theme-on-surface-variant">Ask Claude anything about the household.</native:text>
            @endif

            @foreach ($messages as $message)
                @if ($message['role'] === 'user')
                    <native:column class="w-full items-end">
                        <native:column class="gap-1 p-3 bg-theme-primary rounded-xl">
                            <native:text class="text-base text-theme-on-primary">{{ $message['content'] }}</native:text>
                            @if ($message['time'] !== '')
                                <native:text class="text-xs text-theme-on-primary">{{ $message['time'] }}</native:text>
                            @endif
                        </native:column>
                    </native:column>
                @else
                    <native:column class="w-full items-start">
                        <native:column class="gap-1 p-3 bg-theme-surface rounded-xl">
                            @include('native.chat.markdown', ['blocks' => \App\Support\ChatMarkdown::blocks($message['content'])])
                            @if ($message['time'] !== '')
                                <native:text class="text-xs text-theme-on-surface-variant">{{ $message['time'] }}</native:text>
                            @endif
                        </native:column>
                    </native:column>
                @endif
            @endforeach

            @if ($waiting)
                <native:row class="gap-2 items-center">
                    <native:activity-indicator />
                    <native:text class="text-sm text-theme-on-surface-variant">Claude is thinking…</native:text>
                </native:row>
            @endif
        </native:column>
    </native:scroll-view>

    <native:bottom-bar class="px-4 py-2 glass">
        <native:row class="w-full gap-3 items-center">
            <native:outlined-text-input
                ref="message-input"
                class="flex-1"
                placeholder="Message Claude"
                native:model="draft"
                multiline
                :max-lines="5"
                :max-length="20000"
                keep-focus-on-submit
            />
            <native:button ref="send" label="Send" :disabled="$waiting" @press="send" />
        </native:row>
    </native:bottom-bar>
</native:column>
