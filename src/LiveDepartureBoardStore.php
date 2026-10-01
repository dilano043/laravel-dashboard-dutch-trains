<?php

namespace Creacoon\LiveDepartureBoardTile;

use Spatie\Dashboard\Models\Tile;

class LiveDepartureBoardStore
{
    private Tile $tile;

    public static function make(): static
    {
        return new static;
    }

    public function __construct()
    {
        $this->tile = Tile::firstOrCreateForName('live-departure-board');
    }

    /** @param list<array{time: string, destination: string, service: string, status: string, detail: string, track: string, onTime: bool}> $departures */
    public function setDepartures(array $departures): self
    {
        $this->tile->putData('departures', $departures);

        return $this;
    }

    /** @return list<array{time: string, destination: string, service: string, status: string, detail: string, track: string, onTime: bool}> */
    public function departures(): array
    {
        return $this->tile->getData('departures') ?? [];
    }
}
