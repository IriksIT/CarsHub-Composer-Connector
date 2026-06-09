<?php

declare(strict_types=1);

use CarsHub\Connector\Cache\JsonCacheStore;
use CarsHub\Connector\CarsHubConnector;
use CarsHub\Connector\Client\CarsHubClient;
use CarsHub\Connector\Exceptions\CarsHubApiException;
use CarsHub\Connector\Tests\TestCase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

uses(TestCase::class);

beforeEach(function (): void {
    $this->cacheDir = sys_get_temp_dir() . '/carshub-connector-test-' . uniqid();
    $this->store    = new JsonCacheStore($this->cacheDir);
});

afterEach(function (): void {
    if (is_dir($this->cacheDir)) {
        $files = glob($this->cacheDir . '/**/*') ?: [];
        foreach (array_reverse($files) as $f) {
            is_dir($f) ? rmdir($f) : unlink($f);
        }
        @rmdir($this->cacheDir);
    }
});

function makeConnector(JsonCacheStore $store, array $responses = []): CarsHubConnector
{
    HttpFacade::fake($responses);

    $client = new CarsHubClient(
        http: app(Http::class),
        baseUrl: 'https://carshub.nl/api',
        apiKey: 'test-key',
        crewSlug: 'test-crew',
        timeout: 5,
    );

    return new CarsHubConnector(cache: $store, client: $client, syncOnBoot: false);
}

describe('CarsHubConnector', function (): void {
    it('fetches and caches a page on first read', function (): void {
        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/pages/home' => HttpFacade::response(['title' => 'Home']),
        ]);

        $result = $connector->page('home');

        expect($result)->toBe(['title' => 'Home'])
            ->and($this->store->isMissing('pages.home'))->toBeFalse();
    });

    it('returns cached page data without hitting the API on second read', function (): void {
        $this->store->put('pages.home', ['title' => 'Cached Home']);

        $connector = makeConnector($this->store);

        HttpFacade::assertNothingSent();

        $result = $connector->page('home');
        expect($result)->toBe(['title' => 'Cached Home']);
    });

    it('returns stale data and does not crash when cache is expired', function (): void {
        $this->store->put('members', [['name' => 'Sam']]);

        // Override TTL to 0 so it's immediately stale
        config(['carshub.cache.ttl.members' => 0]);

        $connector = makeConnector($this->store);
        $result    = $connector->members();

        // Stale data should be served (stale-while-revalidate)
        expect($result)->toBe([['name' => 'Sam']]);
    });

    it('returns null for a page when API fails and cache is empty', function (): void {
        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/pages/home' => HttpFacade::response([], 500),
        ]);

        $result = $connector->page('home');
        expect($result)->toBeNull();
    });

    it('syncs only stale keys when force is false', function (): void {
        // Put fresh events cache
        $this->store->put('events.upcoming', [['id' => 1]]);
        $this->store->put('events.past', [['id' => 2]]);

        HttpFacade::fake([
            'https://carshub.nl/api/crews/test-crew/members' => HttpFacade::response([['id' => 1]]),
            'https://carshub.nl/api/crews/test-crew/cars'    => HttpFacade::response([]),
            'https://carshub.nl/api/crews/test-crew/stats'   => HttpFacade::response(['total' => 5]),
            'https://carshub.nl/api/crews/test-crew/pages/*' => HttpFacade::response([]),
        ]);

        $connector = new CarsHubConnector(
            cache: $this->store,
            client: new CarsHubClient(app(Http::class), 'https://carshub.nl/api', 'test-key', 'test-crew', 5),
            syncOnBoot: false,
        );

        $result = $connector->sync(['events'], false);

        expect($result->getSkipped())->toContain('events.upcoming')
            ->and($result->getSkipped())->toContain('events.past');
    });

    it('force sync refreshes fresh cache', function (): void {
        $this->store->put('events.upcoming', [['id' => 1]]);

        HttpFacade::fake([
            'https://carshub.nl/api/crews/test-crew/events/upcoming' => HttpFacade::response([['id' => 99]]),
            'https://carshub.nl/api/crews/test-crew/events/past'     => HttpFacade::response([]),
        ]);

        $connector = new CarsHubConnector(
            cache: $this->store,
            client: new CarsHubClient(app(Http::class), 'https://carshub.nl/api', 'test-key', 'test-crew', 5),
            syncOnBoot: false,
        );

        $result = $connector->sync(['events'], force: true);

        expect($result->getSynced())->toContain('events.upcoming');
        expect($this->store->getStale('events.upcoming'))->toBe([['id' => 99]]);
    });

    it('hasEmptyCache returns true when no files exist', function (): void {
        $connector = makeConnector($this->store);
        expect($connector->hasEmptyCache())->toBeTrue();
    });

    it('clearCache removes all files', function (): void {
        $this->store->put('members', []);
        $this->store->put('events.upcoming', []);

        $connector = makeConnector($this->store);
        $connector->clearCache();

        expect($this->store->isMissing('members'))->toBeTrue()
            ->and($this->store->isMissing('events.upcoming'))->toBeTrue();
    });

    it('cacheStatus lists all expected keys', function (): void {
        $connector = makeConnector($this->store);
        $status    = $connector->cacheStatus();

        expect($status)->toHaveKey('events.upcoming')
            ->toHaveKey('events.past')
            ->toHaveKey('members')
            ->toHaveKey('cars')
            ->toHaveKey('stats');
    });
});
