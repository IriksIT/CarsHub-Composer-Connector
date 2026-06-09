<?php

declare(strict_types=1);

use CarsHub\Connector\Cache\JsonCacheStore;

beforeEach(function (): void {
    $this->dir   = sys_get_temp_dir() . '/carshub-test-' . uniqid();
    $this->cache = new JsonCacheStore($this->dir);
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }
});

describe('JsonCacheStore', function (): void {
    it('returns null for a missing key', function (): void {
        expect($this->cache->get('missing', 3600))->toBeNull();
    });

    it('stores and retrieves data within TTL', function (): void {
        $this->cache->put('pages.home', ['title' => 'Home']);

        expect($this->cache->get('pages.home', 3600))->toBe(['title' => 'Home']);
    });

    it('returns null when TTL has expired', function (): void {
        $this->cache->put('events.upcoming', ['data' => true]);

        // TTL of 0 seconds → immediately stale
        expect($this->cache->get('events.upcoming', 0))->toBeNull();
    });

    it('getStale returns data even when TTL has expired', function (): void {
        $this->cache->put('members', ['id' => 1]);

        expect($this->cache->getStale('members'))->toBe(['id' => 1]);
    });

    it('getStale returns null when the file does not exist', function (): void {
        expect($this->cache->getStale('nonexistent'))->toBeNull();
    });

    it('isMissing returns true before first write', function (): void {
        expect($this->cache->isMissing('stats'))->toBeTrue();
    });

    it('isMissing returns false after write', function (): void {
        $this->cache->put('stats', []);

        expect($this->cache->isMissing('stats'))->toBeFalse();
    });

    it('isStale returns true for missing key', function (): void {
        expect($this->cache->isStale('new-key', 3600))->toBeTrue();
    });

    it('isStale returns false for freshly written key', function (): void {
        $this->cache->put('cars', []);

        expect($this->cache->isStale('cars', 3600))->toBeFalse();
    });

    it('isStale returns true after TTL expires', function (): void {
        $this->cache->put('events.past', []);

        expect($this->cache->isStale('events.past', 0))->toBeTrue();
    });

    it('forget removes the file', function (): void {
        $this->cache->put('pages.about', ['content' => 'test']);
        $this->cache->forget('pages.about');

        expect($this->cache->isMissing('pages.about'))->toBeTrue();
    });

    it('flush removes all cache files', function (): void {
        $this->cache->put('pages.home', []);
        $this->cache->put('members', []);
        $this->cache->flush();

        expect($this->cache->isMissing('pages.home'))->toBeTrue()
            ->and($this->cache->isMissing('members'))->toBeTrue();
    });

    it('fetchedAt returns null for missing key', function (): void {
        expect($this->cache->fetchedAt('ghost'))->toBeNull();
    });

    it('fetchedAt returns a recent timestamp after write', function (): void {
        $before = time();
        $this->cache->put('stats', []);
        $after = time();

        $ts = $this->cache->fetchedAt('stats');

        expect($ts)->toBeGreaterThanOrEqual($before)
            ->and($ts)->toBeLessThanOrEqual($after);
    });

    it('stores nested keys as subdirectories', function (): void {
        $this->cache->put('pages.home', ['slug' => 'home']);

        $expected = $this->dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'home.json';
        expect(file_exists($expected))->toBeTrue();
    });
});
