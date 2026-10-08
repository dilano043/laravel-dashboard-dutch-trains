<?php

namespace Creacoon\LiveDepartureBoardTile\Commands;

use Carbon\CarbonImmutable;
use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FetchLiveDeparturesCommand extends Command
{
    protected $signature = 'dashboard:fetch-live-departure-board';

    protected $description = 'Fetch departures and disruptions for the live departure board';

    public function handle(): int
    {
        $apiKey = config('dashboard.tiles.live_departure_board.api_key');

        if (blank($apiKey)) {
            $store = LiveDepartureBoardStore::make();
            $store->setDepartures([])->markDeparturesStale();
            $store->markDisruptionsStale();
            $this->error('Set dashboard.tiles.live_departure_board.api_key (for example via NS_API_KEY in config/dashboard.php) before fetching live departures.');

            return self::FAILURE;
        }

        $station = config('dashboard.tiles.live_departure_board.station', 'Rm');
        $errors = [];
        $departuresResponse = null;
        $disruptionsResponse = null;

        try {
            $departuresResponse = $this->getNsData('/api/v2/departures', ['station' => $station]);
        } catch (ConnectionException) {
            $errors[] = 'Could not connect to the NS departures API.';
        }

        try {
            $encodedStation = rawurlencode($station);
            $disruptionsPath = "/api/v3/disruptions/station/{$encodedStation}";
            $disruptionsResponse = $this->getNsData($disruptionsPath, [
                'isActive' => 'true',
            ]);
        } catch (ConnectionException) {
            $errors[] = 'Could not connect to the NS disruptions API.';
        }

        $store = LiveDepartureBoardStore::make();
        $departures = $this->storeDepartures($departuresResponse, $store, $errors);
        $disruptions = $this->storeDisruptions($disruptionsResponse, $store, $errors);

        foreach ($errors as $error) {
            $this->error($error);
        }

        $departuresCount = count($departures);
        $disruptionsCount = count($disruptions);

        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->info("Stored {$departuresCount} upcoming departures and {$disruptionsCount} active disruptions.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{
     *     time: string,
     *     destination: string,
     *     service: string,
     *     status: string,
     *     status_code: 'on_time'|'delayed'|'cancelled',
     *     detail: string,
     *     track: string,
     *     planned_at: string,
     *     actual_at: ?string,
     * }>
     */
    private function storeDepartures(?Response $response, LiveDepartureBoardStore $store, array &$errors): array
    {
        if ($response === null) {
            $store->setDepartures([])->markDeparturesStale();

            return [];
        }

        if ($response->failed()) {
            $errors[] = "The NS departures API returned HTTP {$response->status()}.";
            $store->setDepartures([])->markDeparturesStale();

            return [];
        }

        $apiDepartures = $response->json('payload.departures');

        if (! is_array($apiDepartures)) {
            $errors[] = 'The NS departures API response did not contain a departures list.';
            $store->setDepartures([])->markDeparturesStale();

            return [];
        }

        $departures = [];
        $visibleLimit = max(1, (int) config('dashboard.tiles.live_departure_board.visible_departures', 3));

        foreach ($apiDepartures as $apiDeparture) {
            if (! is_array($apiDeparture)) {
                continue;
            }

            if (is_string($apiDeparture['departureStatus'] ?? null)
                && strtoupper($apiDeparture['departureStatus']) === 'DEPARTED') {
                continue;
            }

            $departure = $this->mapDeparture($apiDeparture);

            if ($departure === null) {
                continue;
            }

            if (! CarbonImmutable::parse($departure['actual_at'] ?? $departure['planned_at'])->isFuture()) {
                continue;
            }

            $departures[] = $departure;

            if (count($departures) >= $visibleLimit) {
                break;
            }
        }

        $store->setDepartures($departures);

        return $departures;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{title: string, detail: string}>
     */
    private function storeDisruptions(?Response $response, LiveDepartureBoardStore $store, array &$errors): array
    {
        if ($response === null) {
            $store->markDisruptionsStale();

            return [];
        }

        if ($response->failed()) {
            $errors[] = "The NS disruptions API returned HTTP {$response->status()}.";
            $store->markDisruptionsStale();

            return [];
        }

        $apiDisruptions = $response->json('payload');

        if (! is_array($apiDisruptions)) {
            $apiDisruptions = $response->json();
        }

        if (is_array($apiDisruptions) && is_array($apiDisruptions['disruptions'] ?? null)) {
            $apiDisruptions = $apiDisruptions['disruptions'];
        }

        if (! is_array($apiDisruptions)) {
            $errors[] = 'The NS disruptions API response did not contain a disruptions list.';
            $store->markDisruptionsStale();

            return [];
        }

        $disruptions = [];

        foreach ($apiDisruptions as $apiDisruption) {
            if (! is_array($apiDisruption)) {
                continue;
            }

            $disruption = $this->mapDisruption($apiDisruption);

            if ($disruption === null) {
                continue;
            }

            $disruptions[] = $disruption;
        }

        $store->setDisruptions($disruptions);

        return $disruptions;
    }

    /** @param array<string, string> $query
     */
    private function getNsData(string $path, array $query): Response
    {
        return Http::acceptJson()
            ->withHeaders([
                'Cache-Control' => 'no-cache',
                'Ocp-Apim-Subscription-Key' => config('dashboard.tiles.live_departure_board.api_key'),
            ])
            ->connectTimeout(3)
            ->timeout(10)
            ->get("https://gateway.apiportal.ns.nl/reisinformatie-api{$path}", $query);
    }

    /** @param array<array-key, mixed> $departure
     * @return array{
     *     time: string,
     *     destination: string,
     *     service: string,
     *     status: string,
     *     status_code: 'on_time'|'delayed'|'cancelled',
     *     detail: string,
     *     track: string,
     *     planned_at: string,
     *     actual_at: ?string,
     * }|null
     */
    private function mapDeparture(array $departure): ?array
    {
        $plannedDateTime = $departure['plannedDateTime'] ?? null;
        $destination = $departure['direction'] ?? null;

        if (! is_string($plannedDateTime) || ! is_string($destination)) {
            return null;
        }

        try {
            $planned = CarbonImmutable::parse($plannedDateTime);
            $actualDateTime = $departure['actualDateTime'] ?? null;
            $actual = is_string($actualDateTime) && $actualDateTime !== ''
                ? CarbonImmutable::parse($actualDateTime)
                : null;
        } catch (Throwable) {
            return null;
        }

        $delayMinutes = $actual === null
            ? 0
            : max(0, intdiv($actual->getTimestamp() - $planned->getTimestamp(), 60));
        $isCancelled = (bool) ($departure['cancelled'] ?? false);
        $isOnTime = ! $isCancelled && $delayMinutes === 0;

        if ($isCancelled) {
            $status = 'Cancelled';
            $detail = 'Service cancelled';

            return [
                'time' => $planned->setTimezone(config('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam'))->format('H:i'),
                'destination' => $destination,
                'service' => (string) data_get($departure, 'product.longCategoryName', $departure['name'] ?? $departure['trainCategory'] ?? 'Train'),
                'status' => $status,
                'status_code' => 'cancelled',
                'detail' => $detail,
                'track' => (string) ($departure['actualTrack'] ?? $departure['plannedTrack'] ?? '—'),
                'planned_at' => $planned->toIso8601String(),
                'actual_at' => $actual?->toIso8601String(),
            ];
        }

        if ($isOnTime) {
            $status = 'On time';
            $detail = 'On schedule';

            return [
                'time' => $planned->setTimezone(config('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam'))->format('H:i'),
                'destination' => $destination,
                'service' => (string) data_get($departure, 'product.longCategoryName', $departure['name'] ?? $departure['trainCategory'] ?? 'Train'),
                'status' => $status,
                'status_code' => 'on_time',
                'detail' => $detail,
                'track' => (string) ($departure['actualTrack'] ?? $departure['plannedTrack'] ?? '—'),
                'planned_at' => $planned->toIso8601String(),
                'actual_at' => $actual?->toIso8601String(),
            ];
        }

        $status = 'Delayed';
        $detail = "+ {$delayMinutes} min";

        return [
            'time' => $planned->setTimezone(config('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam'))->format('H:i'),
            'destination' => $destination,
            'service' => (string) data_get($departure, 'product.longCategoryName', $departure['name'] ?? $departure['trainCategory'] ?? 'Train'),
            'status' => $status,
            'status_code' => 'delayed',
            'detail' => $detail,
            'track' => (string) ($departure['actualTrack'] ?? $departure['plannedTrack'] ?? '—'),
            'planned_at' => $planned->toIso8601String(),
            'actual_at' => $actual?->toIso8601String(),
        ];
    }

    /** @param array<array-key, mixed> $disruption
     * @return array{
     *     title: string,
     *     detail: string,
     * }|null
     */
    private function mapDisruption(array $disruption): ?array
    {
        $title = $disruption['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $situation = data_get($disruption, 'timespans.0.situation');
        $detail = data_get($disruption, 'timespans.0.situation.label')
            ?? (is_string($situation) ? $situation : null)
            ?? $disruption['topic']
            ?? $disruption['summary']
            ?? $disruption['cause']
            ?? '';

        return [
            'title' => trim($title),
            'detail' => is_string($detail) ? trim($detail) : '',
        ];
    }
}
