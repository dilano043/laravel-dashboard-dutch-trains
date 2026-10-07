<?php

namespace Creacoon\LiveDepartureBoardTile;

use Creacoon\LiveDepartureBoardTile\Commands\FetchLiveDeparturesCommand;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class LiveDepartureBoardTileServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FetchLiveDeparturesCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dashboard-live-departure-board-tile'),
        ], 'dashboard-live-departure-board-tile-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dashboard-live-departure-board-tile');

        Livewire::component('live-departure-board-tile', LiveDepartureBoardTileComponent::class);
    }
}
