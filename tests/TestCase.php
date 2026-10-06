<?php

namespace Creacoon\LiveDepartureBoardTile\Tests;

use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardTileServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            LiveDepartureBoardTileServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('dashboard.tiles.live_departure_board.api_key', 'test-api-key');
        $app['config']->set('dashboard.tiles.live_departure_board.station', 'Asd');
        $app['config']->set('dashboard.tiles.live_departure_board.timezone', 'Europe/Amsterdam');
        $app['config']->set('dashboard.tiles.live_departure_board.visible_departures', 5);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dashboard_tiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
}
