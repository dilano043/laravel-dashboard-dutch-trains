# Dutch Trains Dashboard Tile

A Livewire tile for [Spatie Laravel Dashboard](https://github.com/spatie/laravel-dashboard) that displays upcoming departures and active station disruptions using the NS Reisinformatie API.

## Requirements

- PHP 8.4 or later
- Laravel 11, 12, or 13
- Livewire 4
- Spatie Laravel Dashboard 4
- An NS API subscription key for the Reisinformatie API

## Installation

Install the package in your Laravel dashboard application:

```sh
composer require creacoon/laravel-dashboard-dutch-trains
```

Laravel discovers the package service provider automatically. It registers the `dashboard:fetch-live-departure-board` command and the `live-departure-board-tile` Livewire component.

## NS API Key

Create or sign in to an account at the [NS API Portal](https://apiportal.ns.nl/), find the Reisinformatie API in the [API catalogue](https://apiportal.ns.nl/apis), and subscribe to its product. The portal provides a subscription key for requests to the API.

Add the key to the host application's `.env` file:

```dotenv
NS_API_KEY=your-ns-subscription-key
```

The package reads `dashboard.tiles.live_departure_board.api_key`, so the host app must map `NS_API_KEY` to that config key as shown below. Keep the key private and do not commit it to source control.

## Configuration

Add the tile configuration under `tiles` in the host application's `config/dashboard.php`:

```php
'tiles' => [
    // Other dashboard tiles...
    'live_departure_board' => [
        'api_key' => env('NS_API_KEY'),
        'station' => 'Rm',
        'station_name' => 'Roermond',
        'visible_departures' => 5,
        'visible_disruptions' => 2,
        'timezone' => 'Europe/Amsterdam',
    ],
],
```

Use an NS station code for `station` and the human-readable station name for `station_name`. `visible_departures` controls the maximum number of departures shown; it is clamped to at least one. `visible_disruptions` controls how many disruptions appear in the tile; any additional active disruptions are counted below the list. `timezone` controls the displayed departure times. After changing cached configuration in production, refresh it with `php artisan config:cache`.

## Schedule Departure Updates

The tile displays the most recently stored departures and active disruptions for the configured station. Schedule the fetch command so the data stays current. In Laravel 11 and later, add this to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dashboard:fetch-live-departure-board')->everyFiveMinutes();
```

Ensure Laravel's scheduler is running on the server. Add the following cron entry, replacing the project path as appropriate:

```cron
* * * * * cd /path/to/your-project && php artisan schedule:run >> /dev/null 2>&1
```

You can also fetch departures manually to verify the configuration:

```sh
php artisan dashboard:fetch-live-departure-board
```

## Add the Tile

Place the Livewire component in the host application's dashboard layout, using an available dashboard position:

```blade
<livewire:live-departure-board-tile position="a1" />
```
