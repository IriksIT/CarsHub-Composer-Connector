<?php

declare(strict_types=1);

namespace CarsHub\Connector;

use CarsHub\Connector\Cache\JsonCacheStore;
use CarsHub\Connector\Client\CarsHubClient;
use CarsHub\Connector\Commands\ClearCacheCommand;
use CarsHub\Connector\Commands\StatusCommand;
use CarsHub\Connector\Commands\SyncCommand;
use CarsHub\Connector\Jobs\SyncJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

final class CarsHubConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/carshub.php', 'carshub');

        $this->app->singleton(JsonCacheStore::class, function (Application $app): JsonCacheStore {
            $configured = self::configString('carshub.cache.path', 'carshub');
            $path = self::isAbsolutePath($configured) ? $configured : storage_path($configured);
            return new JsonCacheStore($path);
        });

        $this->app->singleton(CarsHubClient::class, function (Application $app): CarsHubClient {
            $importKey = config('carshub.event_import_key');

            return new CarsHubClient(
                http: $app->make(Http::class),
                baseUrl: self::configString('carshub.api_base_url', 'https://carshub.nl/api'),
                apiKey: self::configString('carshub.api_key', ''),
                crewSlug: self::configString('carshub.crew_slug', ''),
                timeout: self::configInt('carshub.timeout', 10),
                importKey: is_string($importKey) && $importKey !== '' ? $importKey : null,
            );
        });

        $this->app->singleton(CarsHubConnector::class, function (Application $app): CarsHubConnector {
            return new CarsHubConnector(
                cache: $app->make(JsonCacheStore::class),
                client: $app->make(CarsHubClient::class),
            );
        });
    }

    private static function configString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private static function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/carshub.php' => config_path('carshub.php'),
            ], 'carshub-config');

            $this->commands([
                SyncCommand::class,
                ClearCacheCommand::class,
                StatusCommand::class,
            ]);
        }

        $this->bootSchedule();
        $this->bootInitialSync();
    }

    // -------------------------------------------------------------------------
    // Boot helpers
    // -------------------------------------------------------------------------

    private function bootSchedule(): void
    {
        // Register scheduled sync commands after the application is fully booted
        // so the Schedule instance is available.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Pages and settings: once per day
            $schedule->command('carshub:sync', ['--type' => ['pages']])
                ->daily()
                ->withoutOverlapping()
                ->runInBackground()
                ->name('carshub-sync-pages');

            // Events and members: once per hour
            $schedule->command('carshub:sync', ['--type' => ['events', 'members', 'cars', 'stats']])
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground()
                ->name('carshub-sync-hourly');
        });
    }

    private function bootInitialSync(): void
    {
        if (! (bool) config('carshub.sync_on_boot', true)) {
            return;
        }

        // Only check on non-console requests (console commands handle their own syncing).
        // We defer the check until after booting to avoid resolving the connector during
        // the registration phase.
        $this->app->booted(function (): void {
            if ($this->app->runningInConsole()) {
                return;
            }

            /** @var CarsHubConnector $connector */
            $connector = $this->app->make(CarsHubConnector::class);

            if (! $connector->hasEmptyCache()) {
                return;
            }

            // Dispatch to queue so the web request is not blocked.
            // If the queue driver is 'sync', this runs immediately in-process.
            SyncJob::dispatch(null, false);
        });
    }
}
