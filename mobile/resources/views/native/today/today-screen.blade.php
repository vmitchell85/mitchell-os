<native:refreshable @refresh="refresh">
    <native:column class="w-full gap-4 p-4">
        @if ($error !== '')
            <native:text class="text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($loaded && $error === '')
            @if ($this->weather)
                <native:column class="w-full gap-3 p-4 rounded-2xl bg-theme-surface">
                    <native:row class="w-full items-center gap-3">
                        <native:icon :ios="$this->weather['symbol']['ios']" :android="$this->weather['symbol']['android']" :size="40" class="text-theme-primary" />
                        <native:column class="flex-1">
                            <native:text class="text-3xl font-bold text-theme-on-surface">{{ $this->weather['temperature_f'] }}°</native:text>
                            <native:text class="text-theme-on-surface-variant">{{ $this->weather['conditions'] }}</native:text>
                        </native:column>
                    </native:row>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $this->weather['location'] }}@if ($this->weather['range'] !== '') · {{ $this->weather['range'] }}@endif</native:text>

                    @foreach ($this->weather['alerts'] ?? [] as $alert)
                        <native:text class="text-sm font-semibold text-theme-destructive">{{ $alert['headline'] }}</native:text>
                    @endforeach

                    <native:scroll-view horizontal class="w-full">
                        <native:row class="gap-4">
                            @foreach ($this->weather['hourly'] as $hour)
                                <native:column class="items-center gap-1">
                                    <native:text class="text-xs text-theme-on-surface-variant">{{ $hour['time'] }}</native:text>
                                    <native:icon :ios="$hour['symbol']['ios']" :android="$hour['symbol']['android']" :size="22" class="text-theme-primary" />
                                    <native:text class="text-sm font-semibold text-theme-on-surface">{{ $hour['temperature'] }}</native:text>
                                    @if ($hour['precip'] !== '')
                                        <native:text class="text-xs text-theme-on-surface-variant">{{ $hour['precip'] }}</native:text>
                                    @endif
                                </native:column>
                            @endforeach
                        </native:row>
                    </native:scroll-view>
                </native:column>
            @elseif (! empty($summary['weather_error']))
                <native:text class="text-sm text-theme-on-surface-variant">{{ $summary['weather_error'] }}</native:text>
            @endif

            @if (! empty($summary['schedule_error']))
                <native:text class="text-theme-destructive">{{ $summary['schedule_error'] }}</native:text>
            @endif

            @foreach ($this->days as $dayIndex => $day)
                <native:column class="w-full gap-2">
                    <native:text class="text-lg font-bold text-theme-on-background">{{ $day['label'] }}</native:text>

                    @if ($day['badges'] !== [])
                        <native:row class="w-full flex-row flex-wrap gap-2">
                            @foreach ($day['badges'] as $badge)
                                <native:row class="px-3 py-1 rounded-full bg-[{{ $badge['color'] }}]" a11y-label="{{ $badge['title'] }}, {{ $badge['calendar'] }} calendar, all day">
                                    <native:text class="text-sm font-semibold text-[{{ $badge['on_color'] }}]">{{ $badge['title'] }}</native:text>
                                </native:row>
                            @endforeach
                        </native:row>
                    @endif

                    @foreach ($day['events'] as $event)
                        <native:row class="w-full gap-3 p-4 rounded-lg bg-theme-surface" a11y-label="{{ $event['calendar'] }} calendar">
                            <native:column class="w-1 h-full rounded-full bg-[{{ $event['color'] }}]" />
                            <native:column class="flex-1 gap-1">
                                <native:text class="text-xs text-theme-on-surface-variant">{{ $event['when'] }}</native:text>
                                <native:text class="text-base font-semibold text-theme-on-surface">{{ $event['title'] }}</native:text>
                                @if ($event['location'] !== '')
                                    <native:text class="text-sm text-theme-on-surface-variant">{{ $event['location'] }}</native:text>
                                @endif
                                @if ($event['drive'] !== '')
                                    <native:pressable @press="openDrive({{ $dayIndex }}, {{ $event['index'] }})">
                                        <native:row class="items-center gap-2 pt-1">
                                            <native:icon :ios="\App\Icons\Ios::Car" :android="\App\Icons\Android::DirectionsCar" :size="18" class="text-theme-primary" />
                                            <native:text class="text-sm font-semibold text-theme-primary">{{ $event['drive'] }}</native:text>
                                        </native:row>
                                    </native:pressable>
                                @endif
                            </native:column>
                        </native:row>
                    @endforeach

                    @if ($day['badges'] === [] && $day['events'] === [])
                        <native:text class="text-theme-on-surface-variant">Nothing scheduled.</native:text>
                    @endif
                </native:column>
            @endforeach

            @if (empty($summary['home_address_set']))
                <native:text class="text-sm text-theme-on-surface-variant">Set your home address on the Household page of the web app to see drive times.</native:text>
            @elseif (! empty($summary['drive_error']))
                <native:text class="text-sm text-theme-on-surface-variant">{{ $summary['drive_error'] }}</native:text>
            @endif
        @endif
    </native:column>
</native:refreshable>
