<?php

namespace Creacoon\LiveDepartureBoardTile\Commands;

use Carbon\CarbonImmutable;
use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class FetchLiveDeparturesCommand extends Command
{
    protected $signature = 'dashboard:fetch-live-departure-board';

    protected $description = 'Fetch upcoming departures for the Roermond departure board';

    public function handle(): int
    {
        $apiKey = config('dashboard.tiles.live_departure_board.api_key');

        if (blank($apiKey)) {
            $this->error('Set NS_API_KEY before fetching live departures.');

            return self::FAILURE;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Cache-Control' => 'no-cache',
                    'Ocp-Apim-Subscription-Key' => $apiKey,
                ])
                ->connectTimeout(3)
                ->timeout(10)
                ->get('https://gateway.apiportal.ns.nl/reisinformatie-api/api/v2/departures', [
                    'station' => config('dashboard.tiles.live_departure_board.station', 'Rm'),
                ]);
        } catch (ConnectionException) {
            $this->error('Could not connect to the NS departures API.');

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('The NS departures API returned HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $apiDepartures = $response->json('payload.departures');

        if (! is_array($apiDepartures)) {
            $this->error('The NS departures API response did not contain a departures list.');

            return self::FAILURE;
        }

        $departures = collect($apiDepartures)
            ->filter(fn (mixed $departure): bool => is_array($departure))
            ->map(fn (array $departure): ?array => $this->mapDeparture($departure))
            ->filter()
            ->take(max(0, (int) config('dashboard.tiles.live_departure_board.visible_departures', 3)))
            ->values()
            ->all();

        LiveDepartureBoardStore::make()->setDepartures($departures);

        $this->info('Stored '.count($departures).' upcoming departures.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $departure
     * @return array{time: string, destination: string, service: string, status: string, detail: string, track: string, onTime: bool}|null
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
            || strtoupper((string) ($departure['departureStatus'] ?? '')) === 'CANCELLED';
        $isOnTime = ! $isCancelled && $delayMinutes === 0;
        $status = $isCancelled ? 'Cancelled' : ($isOnTime ? 'On time' : 'Delayed');
        $detail = $isCancelled
            ? 'Service cancelled'
            : ($delayMinutes > 0 ? '+ '.$delayMinutes.' min' : 'On schedule');

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
}
