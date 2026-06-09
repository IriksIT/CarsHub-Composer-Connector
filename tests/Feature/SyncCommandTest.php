<?php

declare(strict_types=1);

use CarsHub\Connector\Cache\JsonCacheStore;
use CarsHub\Connector\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

beforeEach(function (): void {
    $this->cacheDir = sys_get_temp_dir() . '/carshub-cmd-test-' . uniqid();
    config(['carshub.cache.path' => $this->cacheDir]);
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

// Fake all endpoints with valid wrapped responses.
function fakeAll(): void
{
    Http::fake([
        'https://carshub.nl/api/crews/test-crew/pages'           => Http::response(['data' => [
            ['key' => 'home', 'is_enabled' => true, 'title' => null, 'top_text' => null, 'settings' => []],
        ]]),
        'https://carshub.nl/api/crews/test-crew/events/upcoming' => Http::response(['data' => []]),
        'https://carshub.nl/api/crews/test-crew/events/past'     => Http::response(['data' => []]),
        'https://carshub.nl/api/crews/test-crew/members'         => Http::response(['data' => []]),
        'https://carshub.nl/api/crews/test-crew/cars'            => Http::response(['data' => []]),
        'https://carshub.nl/api/crews/test-crew/stats'           => Http::response(['data' => ['members' => 0, 'cars' => 0]]),
    ]);
}

describe('carshub:sync command', function (): void {
    it('exits with success when all syncs succeed', function (): void {
        fakeAll();

        $this->artisan('carshub:sync --force')
            ->assertSuccessful();
    });

    it('exits with failure when API returns an error', function (): void {
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('carshub:sync --force')
            ->assertFailed();
    });

    it('syncs only the specified type', function (): void {
        Http::fake([
            'https://carshub.nl/api/crews/test-crew/members' => Http::response(['data' => [['name' => 'Sam']]]),
        ]);

        $this->artisan('carshub:sync --force --type=members')
            ->assertSuccessful();

        $store = new JsonCacheStore($this->cacheDir);

        expect($store->isMissing('members'))->toBeFalse()
            ->and($store->isMissing('events.upcoming'))->toBeTrue();
    });

    it('syncs pages using the bulk endpoint in a single call', function (): void {
        $called = 0;
        Http::fake([
            'https://carshub.nl/api/crews/test-crew/pages' => function () use (&$called) {
                $called++;
                return Http::response(['data' => [
                    ['key' => 'home',         'is_enabled' => true,  'title' => null, 'top_text' => null, 'settings' => []],
                    ['key' => 'about',        'is_enabled' => true,  'title' => null, 'top_text' => null, 'settings' => []],
                    ['key' => 'crew_list',    'is_enabled' => true,  'title' => null, 'top_text' => null, 'settings' => []],
                    ['key' => 'events',       'is_enabled' => true,  'title' => null, 'top_text' => null, 'settings' => []],
                    ['key' => 'event_detail', 'is_enabled' => false, 'title' => null, 'top_text' => null, 'settings' => []],
                    ['key' => 'contact',      'is_enabled' => true,  'title' => null, 'top_text' => null, 'settings' => []],
                ]]);
            },
        ]);

        $this->artisan('carshub:sync --force --type=pages')
            ->assertSuccessful();

        expect($called)->toBe(1);

        $store = new JsonCacheStore($this->cacheDir);
        expect($store->isMissing('pages'))->toBeFalse();
    });
});

describe('carshub:cache:clear command', function (): void {
    it('removes all cache files', function (): void {
        $store = new JsonCacheStore($this->cacheDir);
        $store->put('members', []);
        $store->put('events.upcoming', []);

        $this->artisan('carshub:cache:clear')->assertSuccessful();

        expect($store->isMissing('members'))->toBeTrue();
    });
});

describe('carshub:status command', function (): void {
    it('shows the cache status table with pages and events', function (): void {
        $this->artisan('carshub:status')
            ->assertSuccessful()
            ->expectsOutputToContain('pages')
            ->expectsOutputToContain('events.upcoming');
    });
});
