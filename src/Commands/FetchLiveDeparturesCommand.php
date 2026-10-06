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
            LiveDepartureBoardStore::make()->markDisruptionsStale();
            $this->error('Set NS_API_KEY before fetching live departures.');

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
            $disruptionsResponse = $this->getNsData('/api/v3/disruptions/station/'.$station, [
                'isActive' => 'true',
            ]);
        } catch (ConnectionException) {
            $errors[] = 'Could not connect to the NS disruptions API.';
        }

        $store = LiveDepartureBoardStore::make();
        $departures = [];

        if ($departuresResponse !== null) {
            if ($departuresResponse->failed()) {
                $errors[] = 'The NS departures API returned HTTP '.$departuresResponse->status().'.';
            } else {
                $apiDepartures = $departuresResponse->json('payload.departures');

                if (! is_array($apiDepartures)) {
                    $errors[] = 'The NS departures API response did not contain a departures list.';
                } else {
                    $departures = collect($apiDepartures)
                        ->filter(fn (mixed $departure): bool => is_array($departure))
                        ->reject(fn (array $departure): bool => is_string($departure['departureStatus'] ?? null)
                            && strtoupper($departure['departureStatus']) === 'DEPARTED')
                        ->map(fn (array $departure): ?array => $this->mapDeparture($departure))
                        ->filter()
                        ->take(max(1, (int) config('dashboard.tiles.live_departure_board.visible_departures', 3)))
                        ->values()
                        ->all();

                    $store->setDepartures($departures);
                }
            }
        }

        if ($disruptionsResponse === null || $disruptionsResponse->failed()) {
            if ($disruptionsResponse !== null) {
                $errors[] = 'The NS disruptions API returned HTTP '.$disruptionsResponse->status().'.';
            }

            $store->markDisruptionsStale();
        } else {
            $apiDisruptions = $disruptionsResponse->json('payload');

            if (! is_array($apiDisruptions)) {
                $apiDisruptions = $disruptionsResponse->json();
            }

            if (is_array($apiDisruptions) && is_array($apiDisruptions['disruptions'] ?? null)) {
                $apiDisruptions = $apiDisruptions['disruptions'];
            }

            if (! is_array($apiDisruptions)) {
                $errors[] = 'The NS disruptions API response did not contain a disruptions list.';
                $store->markDisruptionsStale();
            } else {
                $disruptions = collect($apiDisruptions)
                    ->filter(fn (mixed $disruption): bool => is_array($disruption))
                    ->map(fn (array $disruption): ?array => $this->mapDisruption($disruption))
                    ->filter()
                    ->values()
                    ->all();

                $store->setDisruptions($disruptions);
            }
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->info('Stored '.count($departures).' upcoming departures and '.count($disruptions).' active disruptions.');

        return self::SUCCESS;
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
            ->get('https://gateway.apiportal.ns.nl/reisinformatie-api'.$path, $query);
    }

    /** @param array<string, mixed> $departure
     * @return array{
     *     time: string,
     *     destination: string,
     *     service: string,
     *     status: string,
     *     detail: string,
     *     track: string,
     *     onTime: bool,
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
            : max(0, (int) round(($actual->getTimestamp() - $planned->getTimestamp()) / 60));
        $isCancelled = (bool) ($departure['cancelled'] ?? false)
            || (is_string($departure['departureStatus'] ?? null)
                && strtoupper($departure['departureStatus']) === 'CANCELLED');
        $isOnTime = ! $isCancelled && $delayMinutes === 0;

        if ($isCancelled) {
            $status = 'Cancelled';
            $detail = 'Service cancelled';
        } elseif ($isOnTime) {
            $status = 'On time';
            $detail = 'On schedule';
        } else {
            $status = 'Delayed';
            $detail = '+ '.$delayMinutes.' min';
        }

        return [
            'time' => $planned->setTimezone(config('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam'))->format('H:i'),
            'destination' => $destination,
            'service' => (string) data_get($departure, 'product.longCategoryName', $departure['name'] ?? $departure['trainCategory'] ?? 'Train'),
            'status' => $status,
            'detail' => $detail,
            'track' => (string) ($departure['actualTrack'] ?? $departure['plannedTrack'] ?? '—'),
            'onTime' => $isOnTime,
        ];
    }

    /** @param array<string, mixed> $disruption
     * @return array{title: string, detail: string}|null
     */
    private function mapDisruption(array $disruption): ?array
    {
        $title = $disruption['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        $detail = data_get($disruption, 'timespans.0.situation')
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
