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

    /**
     * @param list<array{
     *     time: string,
     *     destination: string,
     *     service: string,
     *     status: string,
     *     detail: string,
     *     track: string,
     *     onTime: bool,
     * }> $departures
     */
    public function setDepartures(array $departures): static
    {
        $this->tile->putData('departures', $departures);

        return $this;
    }

    /**
     * @param  list<array{title: string, detail: string}>  $disruptions
     */
    public function setDisruptions(array $disruptions): static
    {
        $this->tile->putData('disruptions', $disruptions);
        $this->tile->putData('disruptions_stale', false);

        return $this;
    }

    public function markDisruptionsStale(): static
    {
        $this->tile->putData('disruptions_stale', true);

        return $this;
    }

    /**
     * @return list<array{
     *     time: string,
     *     destination: string,
     *     service: string,
     *     status: string,
     *     detail: string,
     *     track: string,
     *     onTime: bool,
     * }>
     */
    public function departures(): array
    {
        return $this->tile->getData('departures') ?? [];
    }

    /** @return list<array{title: string, detail: string}> */
    public function disruptions(): array
    {
        return $this->tile->getData('disruptions') ?? [];
    }

    public function disruptionsAreStale(): bool
    {
        $stale = $this->tile->getData('disruptions_stale');

        return $stale === null ? $this->disruptions() !== [] : (bool) $stale;
    }
}
