<?php

declare(strict_types=1);

namespace CarsHub\Connector;

use CarsHub\Connector\Cache\JsonCacheStore;
use CarsHub\Connector\Client\CarsHubClient;
use CarsHub\Connector\Exceptions\CarsHubApiException;
use Illuminate\Support\Facades\Log;

/**
 * Main entry point for reading crew data.
 *
 * Every public getter follows the stale-while-revalidate pattern:
 *   1. Return fresh cached data immediately if the TTL has not expired.
 *   2. If the cache is stale but present, return the stale data AND mark
 *      the key for background refresh (dispatched by the scheduler or boot job).
 *   3. If no cache exists at all, fetch synchronously and cache the result.
 *
 * This means web requests are never blocked by an API call after the first
 * priming fetch.
 */
final class CarsHubConnector
{
    /** @var array<string, int> */
    private array $ttl;

    /** @var list<string> */
    private array $pages;

    public function __construct(
        private readonly JsonCacheStore $cache,
        private readonly CarsHubClient $client,
        private readonly bool $syncOnBoot,
    ) {
        $this->ttl   = (array) config('carshub.cache.ttl', []);
        $this->pages = (array) config('carshub.pages', ['home', 'events', 'members', 'cars', 'about']);
    }

    // -------------------------------------------------------------------------
    // Public getters (stale-while-revalidate)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function page(string $slug): ?array
    {
        return $this->resolve("pages.{$slug}", $this->ttl('pages'), fn () => $this->client->getPage($slug));
    }

    /**
     * @param 'upcoming'|'past' $type
     * @return list<array<string, mixed>>
     */
    public function events(string $type = 'upcoming'): array
    {
        /** @var list<array<string, mixed>> */
        return $this->resolve("events.{$type}", $this->ttl('events'), fn () => $this->client->getEvents($type)) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function members(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->resolve('members', $this->ttl('members'), fn () => $this->client->getMembers()) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function cars(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->resolve('cars', $this->ttl('cars'), fn () => $this->client->getCars()) ?? [];
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        /** @var array<string, mixed> */
        return $this->resolve('stats', $this->ttl('stats'), fn () => $this->client->getStats()) ?? [];
    }

    // -------------------------------------------------------------------------
    // Sync API (used by commands and the boot job)
    // -------------------------------------------------------------------------

    /**
     * Sync one or more data types.
     *
     * @param list<string>|null $types  null = sync everything
     */
    public function sync(?array $types = null, bool $force = false): SyncResult
    {
        $result = new SyncResult();

        $all = [
            'pages'          => fn () => $this->syncPages($force, $result),
            'events'         => fn () => $this->syncEvents($force, $result),
            'members'        => fn () => $this->syncMembers($force, $result),
            'cars'           => fn () => $this->syncCars($force, $result),
            'stats'          => fn () => $this->syncStats($force, $result),
        ];

        foreach ($all as $type => $callback) {
            if ($types === null || in_array($type, $types, true)) {
                $callback();
            }
        }

        return $result;
    }

    /** Returns true if any cache file is missing (used by the boot check). */
    public function hasEmptyCache(): bool
    {
        foreach ($this->pages as $slug) {
            if ($this->cache->isMissing("pages.{$slug}")) {
                return true;
            }
        }

        $keys = ['events.upcoming', 'events.past', 'members', 'cars', 'stats'];

        foreach ($keys as $key) {
            if ($this->cache->isMissing($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{key: string, stale: bool, fetched_at: int|null}> */
    public function cacheStatus(): array
    {
        $status = [];

        foreach ($this->pages as $slug) {
            $key           = "pages.{$slug}";
            $status[$key]  = [
                'key'        => $key,
                'stale'      => $this->cache->isStale($key, $this->ttl('pages')),
                'fetched_at' => $this->cache->fetchedAt($key),
            ];
        }

        $typed = [
            'events.upcoming' => 'events',
            'events.past'     => 'events',
            'members'         => 'members',
            'cars'            => 'cars',
            'stats'           => 'stats',
        ];

        foreach ($typed as $key => $type) {
            $status[$key] = [
                'key'        => $key,
                'stale'      => $this->cache->isStale($key, $this->ttl($type)),
                'fetched_at' => $this->cache->fetchedAt($key),
            ];
        }

        return $status;
    }

    public function clearCache(): void
    {
        $this->cache->flush();
    }

    // -------------------------------------------------------------------------
    // Internal sync helpers
    // -------------------------------------------------------------------------

    private function syncPages(bool $force, SyncResult $result): void
    {
        foreach ($this->pages as $slug) {
            $key = "pages.{$slug}";

            if (! $force && ! $this->cache->isStale($key, $this->ttl('pages'))) {
                $result->skipped($key);
                continue;
            }

            $this->fetch($key, fn () => $this->client->getPage($slug), $result);
        }
    }

    private function syncEvents(bool $force, SyncResult $result): void
    {
        foreach (['upcoming', 'past'] as $type) {
            $key = "events.{$type}";

            if (! $force && ! $this->cache->isStale($key, $this->ttl('events'))) {
                $result->skipped($key);
                continue;
            }

            $this->fetch($key, fn () => $this->client->getEvents($type), $result);
        }
    }

    private function syncMembers(bool $force, SyncResult $result): void
    {
        if (! $force && ! $this->cache->isStale('members', $this->ttl('members'))) {
            $result->skipped('members');
            return;
        }

        $this->fetch('members', fn () => $this->client->getMembers(), $result);
    }

    private function syncCars(bool $force, SyncResult $result): void
    {
        if (! $force && ! $this->cache->isStale('cars', $this->ttl('cars'))) {
            $result->skipped('cars');
            return;
        }

        $this->fetch('cars', fn () => $this->client->getCars(), $result);
    }

    private function syncStats(bool $force, SyncResult $result): void
    {
        if (! $force && ! $this->cache->isStale('stats', $this->ttl('stats'))) {
            $result->skipped('stats');
            return;
        }

        $this->fetch('stats', fn () => $this->client->getStats(), $result);
    }

    // -------------------------------------------------------------------------
    // Core helpers
    // -------------------------------------------------------------------------

    /**
     * Stale-while-revalidate read:
     * - Fresh cache    → return immediately
     * - Stale cache    → return stale data (background refresh happens via scheduler)
     * - No cache       → fetch synchronously, store, return
     */
    private function resolve(string $key, int $ttl, callable $fetcher): mixed
    {
        $fresh = $this->cache->get($key, $ttl);

        if ($fresh !== null) {
            return $fresh;
        }

        // Cache file exists but is stale — serve stale, refresh happens in background
        $stale = $this->cache->getStale($key);

        if ($stale !== null) {
            return $stale;
        }

        // Nothing in cache at all — must fetch synchronously
        try {
            $data = $fetcher();
            $this->cache->put($key, $data);
            return $data;
        } catch (CarsHubApiException $e) {
            Log::warning("CarsHub: failed to fetch {$key}: {$e->getMessage()}");
            return null;
        }
    }

    private function fetch(string $key, callable $fetcher, SyncResult $result): void
    {
        try {
            $data = $fetcher();
            $this->cache->put($key, $data);
            $result->synced($key);
        } catch (CarsHubApiException $e) {
            Log::error("CarsHub sync failed for {$key}: {$e->getMessage()}");
            $result->failed($key, $e->getMessage());
        }
    }

    private function ttl(string $type): int
    {
        return isset($this->ttl[$type]) ? (int) $this->ttl[$type] : 3600;
    }
}
