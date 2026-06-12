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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->cacheDir);
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

    return new CarsHubConnector(cache: $store, client: $client);
}

describe('CarsHubConnector', function (): void {
    it('fetches all pages in one call and caches them', function (): void {
        $allPages = [
            ['key' => 'home',         'is_enabled' => true,  'title' => 'Home',    'top_text' => null, 'settings' => []],
            ['key' => 'about',        'is_enabled' => true,  'title' => null,       'top_text' => null, 'settings' => []],
            ['key' => 'crew_list',    'is_enabled' => true,  'title' => null,       'top_text' => null, 'settings' => []],
            ['key' => 'events',       'is_enabled' => true,  'title' => null,       'top_text' => null, 'settings' => []],
            ['key' => 'event_detail', 'is_enabled' => false, 'title' => null,       'top_text' => null, 'settings' => []],
            ['key' => 'contact',      'is_enabled' => true,  'title' => 'Contact',  'top_text' => null, 'settings' => []],
        ];

        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/pages' => HttpFacade::response(['data' => $allPages]),
        ]);

        $result = $connector->pages();

        expect($result)->toHaveCount(6)
            ->and($result[0]['key'])->toBe('home')
            ->and($this->store->isMissing('pages'))->toBeFalse();
    });

    it('fetches and caches an individual page on first read', function (): void {
        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/pages/home' => HttpFacade::response(['data' => ['key' => 'home', 'title' => 'Home']]),
        ]);

        $result = $connector->page('home');

        expect($result)->toBe(['key' => 'home', 'title' => 'Home'])
            ->and($this->store->isMissing('pages.home'))->toBeFalse();
    });

    it('returns cached page data without hitting the API on second read', function (): void {
        $this->store->put('pages.home', ['key' => 'home', 'title' => 'Cached Home']);

        $connector = makeConnector($this->store);

        HttpFacade::assertNothingSent();

        $result = $connector->page('home');
        expect($result)->toBe(['key' => 'home', 'title' => 'Cached Home']);
    });

    it('fetches event detail including attendees', function (): void {
        $detail = [
            'id'              => 3,
            'title'           => 'Summer Meet 2026',
            'attendees_count' => 2,
            'attendees'       => [
                ['id' => 1, 'name' => 'Sam',   'username' => 'sam_skyline', 'avatar_url' => null, 'status' => 'attending'],
                ['id' => 2, 'name' => 'Silvia', 'username' => 'silvia_supra', 'avatar_url' => null, 'status' => 'maybe'],
            ],
        ];

        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/events/3' => HttpFacade::response(['data' => $detail]),
        ]);

        $result = $connector->eventDetail(3);

        expect($result)->not->toBeNull()
            ->and($result['attendees_count'])->toBe(2)
            ->and($result['attendees'][0]['status'])->toBe('attending')
            ->and($this->store->isMissing('events.detail.3'))->toBeFalse();
    });

    it('returns null for event detail when API fails and cache is empty', function (): void {
        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/events/99' => HttpFacade::response([], 404),
        ]);

        expect($connector->eventDetail(99))->toBeNull();
    });

    it('returns stale members and does not crash when cache is expired', function (): void {
        $this->store->put('members', [['name' => 'Sam']]);

        config(['carshub.cache.ttl.members' => 0]);

        $connector = makeConnector($this->store);
        $result    = $connector->members();

        expect($result)->toBe([['name' => 'Sam']]);
    });

    it('returns null for a page when API fails and cache is empty', function (): void {
        $connector = makeConnector($this->store, [
            'https://carshub.nl/api/crews/test-crew/pages/home' => HttpFacade::response([], 500),
        ]);

        expect($connector->page('home'))->toBeNull();
    });

    it('syncs only stale keys when force is false', function (): void {
        $this->store->put('events.upcoming', [['id' => 1]]);
        $this->store->put('events.past', [['id' => 2]]);

        HttpFacade::fake([
            'https://carshub.nl/api/crews/test-crew/members' => HttpFacade::response(['data' => [['id' => 1]]]),
            'https://carshub.nl/api/crews/test-crew/cars'    => HttpFacade::response(['data' => []]),
            'https://carshub.nl/api/crews/test-crew/stats'   => HttpFacade::response(['data' => ['total' => 5]]),
            'https://carshub.nl/api/crews/test-crew/pages'   => HttpFacade::response(['data' => []]),
        ]);

        $connector = new CarsHubConnector(
            cache: $this->store,
            client: new CarsHubClient(app(Http::class), 'https://carshub.nl/api', 'test-key', 'test-crew', 5),
        );

        $result = $connector->sync(['events'], false);

        expect($result->getSkipped())->toContain('events.upcoming')
            ->and($result->getSkipped())->toContain('events.past');
    });

    it('force sync refreshes fresh cache', function (): void {
        $this->store->put('events.upcoming', [['id' => 1]]);

        HttpFacade::fake([
            'https://carshub.nl/api/crews/test-crew/events/upcoming' => HttpFacade::response(['data' => [['id' => 99]]]),
            'https://carshub.nl/api/crews/test-crew/events/past'     => HttpFacade::response(['data' => []]),
        ]);

        $connector = new CarsHubConnector(
            cache: $this->store,
            client: new CarsHubClient(app(Http::class), 'https://carshub.nl/api', 'test-key', 'test-crew', 5),
        );

        $result = $connector->sync(['events'], force: true);

        expect($result->getSynced())->toContain('events.upcoming');
        expect($this->store->getStale('events.upcoming'))->toBe([['id' => 99]]);
    });

    it('hasEmptyCache returns true when no files exist', function (): void {
        $connector = makeConnector($this->store);
        expect($connector->hasEmptyCache())->toBeTrue();
    });

    it('hasEmptyCache returns false when all core keys are present', function (): void {
        foreach (['pages', 'events.upcoming', 'events.past', 'members', 'cars', 'stats'] as $key) {
            $this->store->put($key, []);
        }

        $connector = makeConnector($this->store);
        expect($connector->hasEmptyCache())->toBeFalse();
    });

    it('clearCache removes all files', function (): void {
        $this->store->put('members', []);
        $this->store->put('events.upcoming', []);
        $this->store->put('pages', []);

        $connector = makeConnector($this->store);
        $connector->clearCache();

        expect($this->store->isMissing('members'))->toBeTrue()
            ->and($this->store->isMissing('events.upcoming'))->toBeTrue()
            ->and($this->store->isMissing('pages'))->toBeTrue();
    });

    it('cacheStatus lists all expected keys', function (): void {
        $connector = makeConnector($this->store);
        $status    = $connector->cacheStatus();

        expect($status)->toHaveKey('pages')
            ->toHaveKey('events.upcoming')
            ->toHaveKey('events.past')
            ->toHaveKey('members')
            ->toHaveKey('cars')
            ->toHaveKey('stats');
    });
});
