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
- Laravel 10, 11, 12, or 13

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

# System-managed crews only — needed for event import (create/update/delete)
CARSHUB_EVENT_IMPORT_KEY=eik_...
```

You can find `CARSHUB_API_KEY` and `CARSHUB_CREW_SLUG` in **Crew Settings → Website Sync** on carshub.nl. The `CARSHUB_EVENT_IMPORT_KEY` is shown when the crew is created via the `crews:create-system` command.

Make sure Laravel's scheduler is running:

```bash
# Add to your server's crontab:
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Usage

### Blade / controllers

```php
use CarsHub\Connector\Facades\CarsHub;

// All six page configurations in one call — use this in your layout/middleware
// to determine which pages are enabled and what their titles/settings are.
// Keys: home, about, crew_list, events, event_detail, contact
$allPages = CarsHub::pages();

// Or fetch a single page by key (useful for page-level controllers)
$home = CarsHub::page('home');

// Upcoming and past events (list — id, title, location, starts_at, ends_at, avatar_url, banner_url)
$upcoming = CarsHub::events('upcoming');
$past     = CarsHub::events('past');

// Full event detail including the attendee list (attending + maybe)
$detail = CarsHub::eventDetail(3);
// $detail['attendees']       — list of attendees
// $detail['attendees_count'] — total attending/maybe count

// Crew member roster (id, name, username, avatar_url, role, staff_title, joined_at)
$members = CarsHub::members();

// All active cars owned by crew members
$cars = CarsHub::cars();

// Crew overview stats (members, cars, representatives, past_events, upcoming_events)
// Also includes crew info (name, description, avatar_url, banner_url)
$stats = CarsHub::stats();
```

### Event import (system-managed crews only)

If your crew is a system-managed crew with an `CARSHUB_EVENT_IMPORT_KEY` configured, you can write events directly from your application. All write methods throw `CarsHubApiException` on failure — wrap them in a try/catch.

```php
use CarsHub\Connector\Facades\CarsHub;
use CarsHub\Connector\Exceptions\CarsHubApiException;

try {
    // Create a new event — returns the created event array
    $event = CarsHub::createEvent([
        'title'        => 'Silvia Cup Round 1',
        'description'  => 'First round of the Nissan Silvia one-make series.',
        'location'     => 'Circuit Zandvoort',
        'address'      => 'Burgemeester van Alphenstraat 108, Zandvoort',
        'starts_at'    => '2026-08-15T09:00:00Z',
        'ends_at'      => '2026-08-15T17:00:00Z',
        'members_only' => false,
    ]);
    $eventId = $event['id'];

    // Update an existing event (replaces all fields)
    $updated = CarsHub::updateEvent($eventId, [
        'title'     => 'Silvia Cup Round 1 — Updated',
        'starts_at' => '2026-08-15T09:00:00Z',
        'ends_at'   => '2026-08-15T18:00:00Z',
    ]);

    // Delete an event permanently
    CarsHub::deleteEvent($eventId);
} catch (CarsHubApiException $e) {
    // Log or surface the error as appropriate for your application
    logger()->error('CarsHub event import failed: ' . $e->getMessage());
}
```

After each write the local events cache (`events.upcoming`, `events.past`, and the event detail if cached) is invalidated automatically, so the next read will return fresh data.

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
| `event_import_key` | `env('CARSHUB_EVENT_IMPORT_KEY')` | Event import key (system-managed crews only) |
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
  pages.json                  ← all 6 page configs (bulk endpoint, refreshed daily)
  pages/
    home.json                 ← only written if you call CarsHub::page('home') directly
    about.json
    …
  events/
    upcoming.json
    past.json
    detail/
      3.json                  ← written on first CarsHub::eventDetail(3) call
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
