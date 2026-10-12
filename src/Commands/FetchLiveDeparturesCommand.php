<?php

namespace Creacoon\LiveDepartureBoardTile\Commands;

use Carbon\CarbonImmutable;
use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FetchLiveDeparturesCommand extends Command
{
    protected $signature = 'dashboard:fetch-live-departure-board {--station=}';

    protected $description = 'Fetch departures and disruptions for the live departure board';

    public function handle(): int
    {
        $apiKey = config('dashboard.tiles.live_departure_board.api_key');

        if (blank($apiKey)) {
            $store = LiveDepartureBoardStore::make();
            $store->markDeparturesStale();
            $store->markDisruptionsStale();
            $this->error('Set dashboard.tiles.live_departure_board.api_key (for example via NS_API_KEY in config/dashboard.php) before fetching live departures.');

            return self::FAILURE;
        }

        $station = (string) ($this->option('station') ?: config('dashboard.tiles.live_departure_board.station', 'Rm'));
        $errors = [];

        try {
            $encodedStation = rawurlencode($station);
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('departures')
                    ->acceptJson()
                    ->withHeaders([
                        'Cache-Control' => 'no-cache',
                        'Ocp-Apim-Subscription-Key' => $apiKey,
                    ])
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get('https://gateway.apiportal.ns.nl/reisinformatie-api/api/v2/departures', [
                        'station' => $station,
                    ]),
                $pool->as('disruptions')
                    ->acceptJson()
                    ->withHeaders([
                        'Cache-Control' => 'no-cache',
                        'Ocp-Apim-Subscription-Key' => $apiKey,
                    ])
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get("https://gateway.apiportal.ns.nl/reisinformatie-api/api/v3/disruptions/station/{$encodedStation}", [
                        'isActive' => 'true',
                    ]),
            ]);
        } catch (ConnectionException) {
            $responses = [];
        }

        $departuresResponse = $responses['departures'] ?? null;

        if (! $departuresResponse instanceof Response) {
            $errors[] = 'Could not connect to the NS departures API.';
            $departuresResponse = null;
        }

        $disruptionsResponse = $responses['disruptions'] ?? null;

        if (! $disruptionsResponse instanceof Response) {
            $errors[] = 'Could not connect to the NS disruptions API.';
            $disruptionsResponse = null;
        }

        $store = LiveDepartureBoardStore::make($station);
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
            $store->markDeparturesStale();

            return [];
        }

        if ($response->failed()) {
            $errors[] = "The NS departures API returned HTTP {$response->status()}.";
            $store->markDeparturesStale();

            return [];
        }

        $apiDepartures = $response->json('payload.departures');

        if (! is_array($apiDepartures)) {
            $errors[] = 'The NS departures API response did not contain a departures list.';
            $store->markDeparturesStale();

            return [];
        }

        $departures = [];
        foreach ($apiDepartures as $apiDeparture) {
            if (! is_array($apiDeparture)) {
                continue;
            }

            if (is_string($apiDeparture['departureStatus'] ?? null)) {
                if (strtoupper($apiDeparture['departureStatus']) === 'DEPARTED') {
                    continue;
                }
            }

            $departure = $this->mapDeparture($apiDeparture);

            if ($departure === null) {
                continue;
            }

            if (! CarbonImmutable::parse($departure['actual_at'] ?? $departure['planned_at'])->isFuture()) {
                continue;
            }

            $departures[] = $departure;
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

            if (($apiDisruption['isActive'] ?? true) !== true) {
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

        if (! is_string($plannedDateTime)) {
            return null;
        }

        if (! is_string($destination)) {
            return null;
        }

        try {
            $planned = CarbonImmutable::parse($plannedDateTime);
            $actualDateTime = $departure['actualDateTime'] ?? null;
            $actual = null;

            if (is_string($actualDateTime)) {
                if ($actualDateTime !== '') {
                    $actual = CarbonImmutable::parse($actualDateTime);
                }
            }
        } catch (Throwable) {
            return null;
        }

        $delayMinutes = $actual === null
            ? 0
            : max(0, intdiv($actual->getTimestamp() - $planned->getTimestamp(), 60));
        $isCancelled = (bool) ($departure['cancelled'] ?? false);
        $isOnTime = ! $isCancelled && $delayMinutes === 0;
        [$status, $statusCode, $detail] = match (true) {
            $isCancelled => ['Cancelled', 'cancelled', 'Service cancelled'],
            $isOnTime => ['On time', 'on_time', 'On schedule'],
            default => ['Delayed', 'delayed', "+ {$delayMinutes} min"],
        };

        return [
            'time' => $planned->setTimezone(config('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam'))->format('H:i'),
            'destination' => $destination,
            'service' => (string) data_get($departure, 'product.longCategoryName', $departure['name'] ?? $departure['trainCategory'] ?? 'Train'),
            'status' => $status,
            'status_code' => $statusCode,
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

        if (! is_string($title)) {
            return null;
        }

        if (trim($title) === '') {
            return null;
        }

        $situation = data_get($disruption, 'timespans.0.situation');
        $detail = '';

        foreach ([
            data_get($disruption, 'timespans.0.situation.label'),
            is_string($situation) ? $situation : null,
            $disruption['description'] ?? null,
            $disruption['topic'] ?? null,
            $disruption['summary'] ?? null,
            $disruption['cause'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            if (trim($candidate) === '') {
                continue;
            }

            $detail = trim($candidate);
            break;
        }

        return [
            'title' => trim($title),
            'detail' => $detail,
        ];
    }
}
