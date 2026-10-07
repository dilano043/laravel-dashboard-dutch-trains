<?php

namespace Creacoon\LiveDepartureBoardTile;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class LiveDepartureBoardTileComponent extends Component
{
    public string $position;

    public function mount(string $position): void
    {
        $this->position = $position;
    }

    public function render(): View
    {
        $store = LiveDepartureBoardStore::make();
        $allDisruptions = $store->disruptions();
        $disruptionsAreStale = $store->disruptionsAreStale();
        $visibleDisruptions = array_slice(
            $allDisruptions,
            0,
            max(1, (int) config('dashboard.tiles.live_departure_board.visible_disruptions', 2)),
        );

        $departures = collect($store->departures())
            ->filter(fn (array $departure): bool => $this->isFutureDeparture($departure))
            ->values()
            ->all();

        $departuresAreStale = $store->departuresAreStale();

        return view('dashboard-live-departure-board-tile::tile', [
            'departures' => $departures,
            'departures_are_stale' => $departuresAreStale,
            'departures_stale_message' => 'Departure information unavailable; the latest refresh failed.',
            'disruptions' => $visibleDisruptions,
            'additional_disruptions_count' => count($allDisruptions) - count($visibleDisruptions),
            'disruptions_are_stale' => $disruptionsAreStale,
            'disruptions_stale_message' => $allDisruptions === []
                ? 'Disruption information unavailable; the latest refresh failed.'
                : 'Disruption update failed; showing last known information.',
            'station_name' => config('dashboard.tiles.live_departure_board.station_name')
                ?: config('dashboard.tiles.live_departure_board.station', 'Station'),
        ]);
    }

    /** @param array<string, mixed> $departure */
    private function isFutureDeparture(array $departure): bool
    {
        $plannedAt = $departure['planned_at'] ?? $departure['plannedDateTime'] ?? null;
        $actualAt = $departure['actual_at'] ?? $departure['actualDateTime'] ?? null;
        $timestamp = is_string($actualAt) && $actualAt !== '' ? $actualAt : $plannedAt;

        if (! is_string($timestamp) || $timestamp === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($timestamp)->isFuture();
        } catch (Throwable) {
            return true;
        }
    }
}
