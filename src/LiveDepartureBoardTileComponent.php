<?php

namespace Creacoon\LiveDepartureBoardTile;

use Illuminate\Contracts\View\View;
use Livewire\Component;

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

        return view('dashboard-live-departure-board-tile::tile', [
            'departures' => $store->departures(),
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
}
