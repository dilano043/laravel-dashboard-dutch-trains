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
     *     status_code: 'on_time'|'delayed'|'cancelled',
     *     detail: string,
     *     track: string,
     *     planned_at?: string,
     *     actual_at?: ?string,
     * }> $departures
     */
    public function setDepartures(array $departures): static
    {
        $this->updateData([
            'departures' => $departures,
            'departures_stale' => false,
        ]);

        return $this;
    }

    public function markDeparturesStale(): static
    {
        $this->tile->putData('departures_stale', true);

        return $this;
    }

    /**
     * @param  list<array{title: string, detail: string}>  $disruptions
     */
    public function setDisruptions(array $disruptions): static
    {
        $this->updateData([
            'disruptions' => $disruptions,
            'disruptions_stale' => false,
        ]);

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
     *     status_code: 'on_time'|'delayed'|'cancelled',
     *     detail: string,
     *     track: string,
     *     planned_at?: string,
     *     actual_at?: ?string,
     * }>
     */
    public function departures(): array
    {
        return $this->tile->getData('departures') ?? [];
    }

    public function departuresAreStale(): bool
    {
        return (bool) $this->tile->getData('departures_stale');
    }

    /** @return list<array{title: string, detail: string}> */
    public function disruptions(): array
    {
        return $this->tile->getData('disruptions') ?? [];
    }

    public function disruptionsAreStale(): bool
    {
        return (bool) $this->tile->getData('disruptions_stale');
    }

    /** @param array<string, mixed> $data */
    private function updateData(array $data): void
    {
        $this->tile->update([
            'data' => array_merge($this->tile->data ?? [], $data),
        ]);
    }
}
