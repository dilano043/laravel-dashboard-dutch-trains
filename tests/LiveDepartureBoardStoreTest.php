<?php

namespace Creacoon\LiveDepartureBoardTile\Tests;

use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardStore;
use Illuminate\Support\Facades\DB;
use Spatie\Dashboard\Models\Tile;

class LiveDepartureBoardStoreTest extends TestCase
{
    public function test_updates_departures_and_freshness_in_one_database_write(): void
    {
        $store = LiveDepartureBoardStore::make();
        $store->markDeparturesStale();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $departures = [[
            'time' => '12:00',
            'destination' => 'Utrecht',
            'service' => 'Intercity',
            'status' => 'On time',
            'status_code' => 'on_time',
            'detail' => 'On schedule',
            'track' => '1',
        ]];

        $store->setDepartures($departures);

        self::assertCount(1, DB::getQueryLog());
        self::assertSame($departures, $store->departures());
        self::assertFalse($store->departuresAreStale());
    }

    public function test_updates_disruptions_and_freshness_in_one_database_write(): void
    {
        $store = LiveDepartureBoardStore::make();
        $store->markDisruptionsStale();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $disruptions = [[
            'title' => 'Disruption near Roermond',
            'detail' => 'Trains are running less frequently.',
        ]];

        $store->setDisruptions($disruptions);

        self::assertCount(1, DB::getQueryLog());
        self::assertSame($disruptions, $store->disruptions());
        self::assertFalse($store->disruptionsAreStale());
    }

    public function test_treats_saved_disruptions_without_a_stale_flag_as_fresh(): void
    {
        Tile::firstOrCreateForName('live-departure-board')->update([
            'data' => [
                'disruptions' => [[
                    'title' => 'Disruption near Roermond',
                    'detail' => 'Trains are running less frequently.',
                ]],
            ],
        ]);

        $store = LiveDepartureBoardStore::make();

        self::assertFalse($store->disruptionsAreStale());
    }

    public function test_treats_saved_departures_without_a_stale_flag_as_fresh(): void
    {
        Tile::firstOrCreateForName('live-departure-board')->update([
            'data' => [
                'departures' => [[
                    'time' => '12:00',
                    'destination' => 'Utrecht',
                    'service' => 'Intercity',
                    'status' => 'On time',
                    'status_code' => 'on_time',
                    'detail' => 'On schedule',
                    'track' => '1',
                ]],
            ],
        ]);

        $store = LiveDepartureBoardStore::make();

        self::assertFalse($store->departuresAreStale());
    }
}
