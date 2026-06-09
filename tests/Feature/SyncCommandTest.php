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

describe('carshub:sync command', function (): void {
    it('exits with success when all syncs succeed', function (): void {
        Http::fake([
            'https://carshub.nl/api/crews/test-crew/pages/*'          => Http::response(['title' => 'Test']),
            'https://carshub.nl/api/crews/test-crew/events/upcoming'  => Http::response([]),
            'https://carshub.nl/api/crews/test-crew/events/past'      => Http::response([]),
            'https://carshub.nl/api/crews/test-crew/members'          => Http::response([]),
            'https://carshub.nl/api/crews/test-crew/cars'             => Http::response([]),
            'https://carshub.nl/api/crews/test-crew/stats'            => Http::response(['total' => 0]),
        ]);

        $this->artisan('carshub:sync --force')
            ->assertSuccessful();
    });

    it('exits with failure when API returns an error', function (): void {
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $this->artisan('carshub:sync --force')
            ->assertFailed();
    });

    it('syncs only the specified type', function (): void {
        Http::fake([
            'https://carshub.nl/api/crews/test-crew/members' => Http::response([['name' => 'Sam']]),
        ]);

        $this->artisan('carshub:sync --force --type=members')
            ->assertSuccessful();

        $store = new JsonCacheStore($this->cacheDir);

        expect($store->isMissing('members'))->toBeFalse()
            ->and($store->isMissing('events.upcoming'))->toBeTrue();
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
    it('shows the cache status table', function (): void {
        $this->artisan('carshub:status')
            ->assertSuccessful()
            ->expectsOutputToContain('events.upcoming');
    });
});
