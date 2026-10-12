<?php

namespace Creacoon\LiveDepartureBoardTile;

use Spatie\Dashboard\Models\Tile;

final class LiveDepartureBoardStore
{
    private Tile $tile;

    public static function make(?string $station = null): static
    {
        return new self($station);
    }

    public function __construct(?string $station = null)
    {
        $configuredStation = (string) config('dashboard.tiles.live_departure_board.station', 'Rm');
        $station ??= $configuredStation;
        $canMigrateLegacyData = strcasecmp(trim($station), trim($configuredStation)) === 0;
        $station = strtolower(trim($station));
        $tileName = "live-departure-board-{$station}";

        $this->tile = Tile::firstOrCreateForName($tileName);

        if (! $this->tile->wasRecentlyCreated || ! $canMigrateLegacyData) {
            return;
        }

        $legacyTile = Tile::query()->where('name', 'live-departure-board')->first();

        if ($legacyTile !== null) {
            $this->tile->update(['data' => $legacyTile->data]);
        }
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
     * @param list<array{
     *     title: string,
     *     detail: string,
     * }> $disruptions
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

    /**
     * @return list<array{
     *     title: string,
     *     detail: string,
     * }>
     */
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
