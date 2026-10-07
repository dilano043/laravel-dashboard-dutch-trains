<?php

namespace Creacoon\LiveDepartureBoardTile\Tests\Commands;

use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardStore;
use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardTileComponent;
use Creacoon\LiveDepartureBoardTile\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class FetchLiveDeparturesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 11:00:00+02:00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_stores_on_time_departures(): void
    {
        $departures = $this->fetchDepartures([
            $this->apiDeparture('Utrecht'),
        ]);

        self::assertSame([
            'time' => '12:00',
            'destination' => 'Utrecht',
            'service' => 'Intercity',
            'status' => 'On time',
            'status_code' => 'on_time',
            'detail' => 'On schedule',
            'track' => '1',
            'planned_at' => '2026-10-05T12:00:00+02:00',
            'actual_at' => null,
        ], $departures[0]);
    }

    public function test_filters_past_on_time_departure_mapped_from_api_before_rendering(): void
    {
        $this->travelTo('2026-10-05 12:01:00+02:00');

        $this->fetchDepartures([
            $this->apiDeparture('Utrecht'),
        ]);

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');
        $data = $component->render()->getData();

        self::assertSame([], $data['departures']);
    }

    public function test_filters_past_departures_before_applying_visible_limit(): void
    {
        $this->travelTo('2026-10-05 12:01:00+02:00');
        config()->set('dashboard.tiles.live_departure_board.visible_departures', 1);

        $departures = $this->fetchDepartures([
            $this->apiDeparture('Already departed'),
            $this->apiDeparture('Utrecht', [
                'plannedDateTime' => '2026-10-05T12:05:00+02:00',
            ]),
        ]);

        self::assertSame(['Utrecht'], array_column($departures, 'destination'));
    }

    public function test_stores_delayed_departures_with_delay_detail(): void
    {
        $departures = $this->fetchDepartures([
            $this->apiDeparture('Utrecht', [
                'actualDateTime' => '2026-10-05T12:05:00+02:00',
            ]),
        ]);

        self::assertSame('Delayed', $departures[0]['status']);
        self::assertSame('delayed', $departures[0]['status_code']);
        self::assertSame('+ 5 min', $departures[0]['detail']);
    }

    public function test_treats_delays_under_one_minute_as_on_time(): void
    {
        $departures = $this->fetchDepartures([
            $this->apiDeparture('Utrecht', [
                'actualDateTime' => '2026-10-05T12:00:30+02:00',
            ]),
        ]);

        self::assertSame('On time', $departures[0]['status']);
        self::assertSame('on_time', $departures[0]['status_code']);
        self::assertSame('On schedule', $departures[0]['detail']);
    }

    public function test_stores_cancelled_departures_from_cancelled_flag(): void
    {
        $departures = $this->fetchDepartures([
            $this->apiDeparture('Utrecht', [
                'cancelled' => true,
                'departureStatus' => 'ON_STATION',
            ]),
        ]);

        self::assertSame('Cancelled', $departures[0]['status']);
        self::assertSame('cancelled', $departures[0]['status_code']);
        self::assertSame('Service cancelled', $departures[0]['detail']);
        self::assertSame('2026-10-05T12:00:00+02:00', $departures[0]['planned_at']);
        self::assertNull($departures[0]['actual_at']);
    }

    public function test_marks_departures_stale_when_departure_refresh_fails(): void
    {
        LiveDepartureBoardStore::make()->setDepartures([
            [
                'time' => '12:00',
                'destination' => 'Utrecht',
                'service' => 'Intercity',
                'status' => 'On time',
                'status_code' => 'on_time',
                'detail' => 'On schedule',
                'track' => '1',
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v2/departures*' => Http::response([], 503),
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v3/disruptions/station/*' => Http::response([
                'payload' => [],
            ]),
        ]);

        $this->artisan('dashboard:fetch-live-departure-board')->assertFailed();

        $store = LiveDepartureBoardStore::make();
        self::assertSame([], $store->departures());
        self::assertTrue($store->departuresAreStale());
    }

    public function test_stores_active_station_disruptions(): void
    {
        LiveDepartureBoardStore::make()->markDisruptionsStale();

        $this->fetchDepartures([], [
            [
                'title' => 'Disruption near Roermond',
                'topic' => 'Trains are running less frequently.',
                'isActive' => true,
            ],
        ]);

        self::assertSame([
            [
                'title' => 'Disruption near Roermond',
                'detail' => 'Trains are running less frequently.',
            ],
        ], LiveDepartureBoardStore::make()->disruptions());
        self::assertFalse(LiveDepartureBoardStore::make()->disruptionsAreStale());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/disruptions/station/Asd')
            && $request['isActive'] === 'true');
    }

    public function test_stores_disruption_situation_label_from_timespan(): void
    {
        $this->fetchDepartures([], [
            [
                'title' => 'Disruption near Roermond',
                'timespans' => [[
                    'situation' => [
                        'label' => 'Trains are running less frequently.',
                    ],
                ]],
            ],
        ]);

        self::assertSame([
            [
                'title' => 'Disruption near Roermond',
                'detail' => 'Trains are running less frequently.',
            ],
        ], LiveDepartureBoardStore::make()->disruptions());
    }

    public function test_stores_all_active_disruptions_and_reports_hidden_items_to_the_tile(): void
    {
        config()->set('dashboard.tiles.live_departure_board.visible_disruptions', 2);

        $this->fetchDepartures([], [
            ['title' => 'Disruption one', 'topic' => 'Topic one.'],
            ['title' => 'Disruption two', 'topic' => 'Topic two.'],
            ['title' => 'Disruption three', 'topic' => 'Topic three.'],
        ]);

        $store = LiveDepartureBoardStore::make();
        self::assertCount(3, $store->disruptions());

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');
        $data = $component->render()->getData();

        self::assertSame(['Disruption one', 'Disruption two'], array_column($data['disruptions'], 'title'));
        self::assertSame(1, $data['additional_disruptions_count']);
    }

    public function test_clears_saved_disruptions_after_successful_empty_refresh(): void
    {
        LiveDepartureBoardStore::make()
            ->setDisruptions([[
                'title' => 'Old disruption',
                'detail' => 'No longer active.',
            ]])
            ->markDisruptionsStale();

        $this->fetchDepartures([], []);

        $store = LiveDepartureBoardStore::make();
        self::assertSame([], $store->disruptions());
        self::assertFalse($store->disruptionsAreStale());
    }

    public function test_stores_departures_and_marks_last_known_disruptions_stale_when_refresh_fails(): void
    {
        $lastKnownDisruptions = [[
            'title' => 'Disruption near Roermond',
            'detail' => 'Trains are running less frequently.',
        ]];

        LiveDepartureBoardStore::make()->setDisruptions($lastKnownDisruptions);

        Http::preventStrayRequests();
        Http::fake([
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v2/departures*' => Http::response([
                'payload' => [
                    'departures' => [$this->apiDeparture('Utrecht')],
                ],
            ]),
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v3/disruptions/station/*' => Http::response([], 503),
        ]);

        $this->artisan('dashboard:fetch-live-departure-board')->assertFailed();

        $store = LiveDepartureBoardStore::make();
        self::assertSame($lastKnownDisruptions, $store->disruptions());
        self::assertTrue($store->disruptionsAreStale());
        self::assertSame('Utrecht', $store->departures()[0]['destination']);
    }

    public function test_passes_stored_disruptions_to_tile_view(): void
    {
        $disruptions = [
            [
                'title' => 'Disruption near Roermond',
                'detail' => 'Trains are running less frequently.',
            ],
        ];

        LiveDepartureBoardStore::make()
            ->setDisruptions($disruptions)
            ->markDisruptionsStale();

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');
        $data = $component->render()->getData();

        self::assertSame($disruptions, $data['disruptions']);
        self::assertTrue($data['disruptions_are_stale']);
        self::assertSame('Disruption update failed; showing last known information.', $data['disruptions_stale_message']);
    }

    public function test_reports_unavailable_when_disruption_refresh_fails_without_saved_data(): void
    {
        LiveDepartureBoardStore::make()->markDisruptionsStale();

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');
        $data = $component->render()->getData();

        self::assertSame([], $data['disruptions']);
        self::assertTrue($data['disruptions_are_stale']);
        self::assertSame('Disruption information unavailable; the latest refresh failed.', $data['disruptions_stale_message']);
    }

    public function test_filters_past_departures_before_rendering_tile(): void
    {
        $store = LiveDepartureBoardStore::make();
        $store->setDepartures([
            [
                'time' => '12:00',
                'destination' => 'Utrecht',
                'service' => 'Intercity',
                'status' => 'On time',
                'status_code' => 'on_time',
                'detail' => 'On schedule',
                'track' => '1',
                'planned_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            [
                'time' => '12:15',
                'destination' => 'Eindhoven',
                'service' => 'Intercity',
                'status' => 'On time',
                'status_code' => 'on_time',
                'detail' => 'On schedule',
                'track' => '2',
                'planned_at' => now()->addMinutes(5)->toIso8601String(),
            ],
        ]);

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');
        $data = $component->render()->getData();

        self::assertCount(1, $data['departures']);
        self::assertSame('Eindhoven', $data['departures'][0]['destination']);
    }

    public function test_skips_malformed_departures(): void
    {
        $departures = $this->fetchDepartures([
            ['plannedDateTime' => 'not-a-date', 'direction' => 'Invalid time'],
            ['plannedDateTime' => '2026-10-05T12:00:00+02:00'],
            $this->apiDeparture('Invalid actual time', [
                'actualDateTime' => 'not-a-date',
            ]),
            $this->apiDeparture('Utrecht'),
        ]);

        self::assertCount(1, $departures);
        self::assertSame('Utrecht', $departures[0]['destination']);
    }

    public function test_uses_station_code_as_fallback_when_station_name_is_missing(): void
    {
        config()->set('dashboard.tiles.live_departure_board.station_name', null);

        $component = new LiveDepartureBoardTileComponent;
        $component->mount('a1');

        $data = $component->render()->getData();

        self::assertSame('Asd', $data['station_name']);
    }

    /** @param list<array<string, mixed>> $apiDepartures
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
    private function fetchDepartures(array $apiDepartures, array $apiDisruptions = []): array
    {
        Http::preventStrayRequests();
        Http::fake([
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v2/departures*' => Http::response([
                'payload' => [
                    'departures' => $apiDepartures,
                ],
            ]),
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v3/disruptions/station/*' => Http::response([
                'payload' => $apiDisruptions,
            ]),
            'gateway.apiportal.ns.nl/reisinformatie-api/api/v3/disruptions*' => Http::response([
                'payload' => [[
                    'title' => 'Unrelated network disruption',
                    'topic' => 'This must not appear on the station board.',
                ]],
            ]),
        ]);

        $this->artisan('dashboard:fetch-live-departure-board')->assertSuccessful();

        return LiveDepartureBoardStore::make()->departures();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function apiDeparture(string $destination, array $overrides = []): array
    {
        return array_merge([
            'plannedDateTime' => '2026-10-05T12:00:00+02:00',
            'direction' => $destination,
            'departureStatus' => 'INCOMING',
            'cancelled' => false,
            'plannedTrack' => '1',
            'product' => [
                'longCategoryName' => 'Intercity',
            ],
        ], $overrides);
    }
}
