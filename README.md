# CarsHub Connector

A Laravel package that connects your crew's website to [CarsHub](https://carshub.nl). It pulls pages, events, members, and cars from the CarsHub API and keeps them in a local JSON cache so your site stays fast and responsive even when the CarsHub API is unavailable.

## How it works

- **Stale-while-revalidate** — every read returns immediately from cache. If the cache is fresh the request never touches the API. If the cache is stale, the stale data is returned while the scheduler refreshes it in the background.
- **First-run sync** — when no cache files exist the connector fetches all data on first boot and stores it, so your pages work from the first request.
- **Scheduled refresh** — register `php artisan schedule:run` as a cron job once and the connector handles the rest:
  - Pages and settings → refreshed **daily**
  - Events, members, cars, stats → refreshed **hourly**

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require carshub/carshub-connector
```

Publish the config file:

```bash
php artisan vendor:publish --tag=carshub-config
```

Add your credentials to `.env`:

```dotenv
CARSHUB_API_KEY=your-api-key-from-crew-settings
CARSHUB_CREW_SLUG=your-crew-slug
```

You can find both values in **Crew Settings → Website Sync** on carshub.nl.

Make sure Laravel's scheduler is running:

```bash
# Add to your server's crontab:
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Usage

### Blade / controllers

```php
use CarsHub\Connector\Facades\CarsHub;

// Get a page's settings (home, events, members, cars, about)
$home = CarsHub::page('home');

// Get upcoming or past events
$events = CarsHub::events('upcoming');
$past   = CarsHub::events('past');

// Get crew members
$members = CarsHub::members();

// Get crew cars
$cars = CarsHub::cars();

// Get crew stats (member count, car count, event count, etc.)
$stats = CarsHub::stats();
```

### Artisan commands

```bash
# Show cache freshness for all data types
php artisan carshub:status

# Sync all stale data right now
php artisan carshub:sync

# Force-refresh everything regardless of TTL
php artisan carshub:sync --force

# Sync only specific types
php artisan carshub:sync --type=events --type=members

# Clear the local cache (next read will re-fetch from the API)
php artisan carshub:cache:clear
```

## Configuration

All options are in `config/carshub.php` after publishing.

| Key | Default | Description |
|---|---|---|
| `api_key` | `env('CARSHUB_API_KEY')` | API key from Crew Settings → Website Sync |
| `crew_slug` | `env('CARSHUB_CREW_SLUG')` | Your crew's URL slug |
| `api_base_url` | `https://carshub.nl/api` | CarsHub API base URL |
| `cache.path` | `carshub` | Subdirectory under `storage/` for JSON files |
| `cache.ttl.pages` | `86400` | Page cache TTL in seconds (24 h) |
| `cache.ttl.events` | `3600` | Events cache TTL in seconds (1 h) |
| `cache.ttl.members` | `3600` | Members cache TTL in seconds (1 h) |
| `sync_on_boot` | `true` | Dispatch a sync job on first boot if cache is empty |
| `timeout` | `10` | HTTP request timeout in seconds |

## Cache files

Cached data is stored as JSON files under `storage/carshub/`:

```
storage/carshub/
  pages/
    home.json
    events.json
    members.json
    cars.json
    about.json
  events/
    upcoming.json
    past.json
  members.json
  cars.json
  stats.json
```

Each file looks like:

```json
{
  "fetched_at": 1718000000,
  "data": { ... }
}
```

You can safely delete any of these files — the connector will re-fetch on next read.

## Troubleshooting

**Pages return `null`**
Check that `CARSHUB_API_KEY` and `CARSHUB_CREW_SLUG` are set and that the Website Sync module is enabled in your crew settings on CarsHub.

**Cache is always stale**
Run `php artisan carshub:status` to see when each key was last fetched. Run `php artisan carshub:sync --force` to refresh immediately. Make sure `schedule:run` is registered in your crontab.

**Events don't update**
Events refresh every hour. Run `php artisan carshub:sync --type=events --force` to refresh now.
