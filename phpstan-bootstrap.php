<?php

use Creacoon\LiveDepartureBoardTile\LiveDepartureBoardTileServiceProvider;
use Livewire\LivewireServiceProvider;

$app = app();
$app->register(LivewireServiceProvider::class);
$app->register(LiveDepartureBoardTileServiceProvider::class);
