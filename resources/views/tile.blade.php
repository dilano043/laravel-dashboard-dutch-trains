<x-dashboard-tile :position="$position" :refresh-interval="60">
    <div class="-m-4 flex h-[calc(100%+2rem)] flex-col overflow-hidden bg-tile text-default">
        <header class="flex h-[30px] shrink-0 items-center justify-between border-b border-[color-mix(in_srgb,var(--color-default)_18%,transparent)] bg-[color-mix(in_srgb,var(--color-default)_6%,var(--color-tile))] px-3 sm:px-4">
            <h2 class="text-sm font-semibold">Station {{ $station_name }} departure times</h2>
        </header>

        @if($disruptions_are_stale || ! empty($disruptions))
            <section class="shrink-0 border-b border-[color-mix(in_srgb,var(--color-default)_18%,transparent)] bg-[color-mix(in_srgb,var(--color-warning)_10%,var(--color-tile))] px-3 py-1.5 sm:px-4" aria-label="{{ $disruptions_are_stale ? 'Station disruption information is out of date' : 'Active station disruptions' }}">
                @if($disruptions_are_stale)
                    <p class="mb-1 text-[9px] font-semibold text-warning sm:text-[10px]">{{ $disruptions_stale_message }}</p>
                @endif
                @foreach($disruptions as $disruption)
                    <article class="py-1 first:pt-0 last:pb-0">
                        <p class="truncate text-[10px] font-semibold text-warning sm:text-xs">{{ $disruption['title'] }}</p>
                        @if($disruption['detail'] !== '')
                            <p class="line-clamp-2 text-[9px] leading-tight text-dimmed sm:text-[10px]">{{ $disruption['detail'] }}</p>
                        @endif
                    </article>
                @endforeach
                @if($additional_disruptions_count > 0)
                    <p class="pt-1 text-[9px] font-semibold text-dimmed sm:text-[10px]">{{ $additional_disruptions_count }} more active disruption{{ $additional_disruptions_count === 1 ? '' : 's' }} not shown.</p>
                @endif
            </section>
        @endif

        <div class="grid h-[25px] shrink-0 grid-cols-[3.25rem_minmax(0,1fr)_minmax(0,1fr)_2.5rem] items-center gap-1 border-b border-[color-mix(in_srgb,var(--color-default)_18%,transparent)] bg-[color-mix(in_srgb,var(--color-default)_3%,var(--color-tile))] px-2 text-[8px] uppercase text-dimmed sm:grid-cols-[3.75rem_minmax(0,1fr)_minmax(0,1fr)_2.75rem] sm:gap-1.5 sm:px-3 sm:text-[9px]">
            <span>Time</span>
            <span>Direction</span>
            <span>Status</span>
            <span class="text-center">Track</span>
        </div>

        @if($departures_are_stale)
            <div class="shrink-0 border-b border-[color-mix(in_srgb,var(--color-default)_18%,transparent)] bg-[color-mix(in_srgb,var(--color-warning)_10%,var(--color-tile))] px-3 py-1.5 text-[9px] font-semibold text-warning sm:text-[10px]">
                {{ $departures_stale_message }}
            </div>
        @endif

        @if(empty($departures))
            <div class="flex min-h-0 flex-1 items-center justify-center px-4 text-sm text-dimmed">
                No departures available
            </div>
        @else
            <div class="grid min-h-0 flex-1" style="grid-template-rows: repeat({{ count($departures) }}, minmax(0, 1fr))">
                @foreach($departures as $departure)
                    <article class="grid min-h-0 grid-cols-[3.25rem_minmax(0,1fr)_minmax(0,1fr)_2.5rem] items-center gap-1 border-b border-[color-mix(in_srgb,var(--color-default)_18%,transparent)] px-2 last:border-b-0 sm:grid-cols-[3.75rem_minmax(0,1fr)_minmax(0,1fr)_2.75rem] sm:gap-1.5 sm:px-3">
                        <time class="font-mono text-base font-bold tabular-nums text-default sm:text-lg">{{ $departure['time'] }}</time>

                        <div class="min-w-0">
                            <p class="truncate text-xs font-bold leading-tight sm:text-sm">{{ $departure['destination'] }}</p>
                            <p class="truncate text-[7px] leading-tight text-dimmed sm:text-[8px]">{{ $departure['service'] }}</p>
                        </div>

                        <div @class([
                            'min-w-0 text-success' => $departure['status_code'] === 'on_time',
                            'min-w-0 text-warning' => $departure['status_code'] === 'delayed',
                            'min-w-0 text-error' => $departure['status_code'] === 'cancelled',
                        ])>
                            <span @class([
                                'inline-flex max-w-full items-center gap-1 rounded-full px-1.5 py-1 text-[8px] font-medium leading-none sm:gap-1.5 sm:px-2 sm:text-[9px]',
                                'bg-tile',
                            ])>
                                <span @class([
                                    'size-[7px] shrink-0 rounded-full',
                                    'bg-current',
                                ])></span>
                                <span class="truncate">{{ $departure['status'] }}</span>
                            </span>
                            <p class="mt-1 truncate text-[8px] font-semibold leading-tight sm:text-[9px]">{{ $departure['detail'] }}</p>
                        </div>

                        <div class="flex h-10 flex-col items-center justify-center rounded-md border border-[color-mix(in_srgb,var(--color-default)_70%,transparent)] bg-[color-mix(in_srgb,var(--color-default)_5%,var(--color-tile))]">
                            <span class="text-[7px] uppercase text-dimmed">Track</span>
                            <span class="font-mono text-base font-bold leading-none sm:text-lg">{{ $departure['track'] }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-dashboard-tile>