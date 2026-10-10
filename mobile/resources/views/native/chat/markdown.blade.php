<native:column class="gap-2">
    @foreach ($blocks as $block)
        @if ($block['type'] === 'rule')
            <native:column class="w-full h-px bg-theme-on-surface-variant" />
        @elseif ($block['type'] === 'code')
            <native:column class="p-2 bg-theme-surface-variant rounded-lg">
                <native:text class="text-sm font-mono text-theme-on-surface">{{ $block['text'] }}</native:text>
            </native:column>
        @elseif ($block['type'] === 'heading')
            <native:text class="{{ $block['level'] <= 1 ? 'text-xl' : ($block['level'] === 2 ? 'text-lg' : 'text-base') }} font-bold text-theme-on-surface">@include('native.chat.runs', ['runs' => $block['runs']])</native:text>
        @elseif ($block['type'] === 'paragraph')
            <native:text class="text-base text-theme-on-surface">@include('native.chat.runs', ['runs' => $block['runs']])</native:text>
        @else
            <native:row class="w-full gap-2 items-start {{ ['', 'pl-4', 'pl-8', 'pl-12'][$block['indent']] }}">
                <native:text class="text-base text-theme-on-surface">{{ $block['type'] === 'bullet' ? '•' : $block['marker'] }}</native:text>
                <native:text class="flex-1 text-base text-theme-on-surface">@include('native.chat.runs', ['runs' => $block['runs']])</native:text>
            </native:row>
        @endif
    @endforeach
</native:column>
