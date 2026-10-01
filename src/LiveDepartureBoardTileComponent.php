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
        return view('dashboard-live-departure-board-tile::tile', [
            'departures' => LiveDepartureBoardStore::make()->departures(),
        ]);
    }
}