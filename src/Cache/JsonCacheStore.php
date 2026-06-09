<?php

declare(strict_types=1);

namespace CarsHub\Connector\Cache;

use RuntimeException;

/**
 * Stores each data type as a single JSON file under storage/carshub/.
 *
 * File format:
 * {
 *   "fetched_at": 1718000000,
 *   "data": { ... }
 * }
 */
final class JsonCacheStore
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /** Returns the cached data array, or null if the entry is missing or stale. */
    public function get(string $key, int $ttl): mixed
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        /** @var array{fetched_at: int, data: mixed}|null $entry */
        $entry = json_decode($raw, true);

        if (! is_array($entry) || ! isset($entry['fetched_at'], $entry['data'])) {
            return null;
        }

        if ((time() - (int) $entry['fetched_at']) > $ttl) {
            return null;
        }

        return $entry['data'];
    }

    /**
     * Returns stale data even if the TTL has passed, or null if the file
     * does not exist at all.  Useful for returning something while a
     * background refresh is in flight.
     */
    public function getStale(string $key): mixed
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        /** @var array{fetched_at: int, data: mixed}|null $entry */
        $entry = json_decode($raw, true);

        return is_array($entry) ? $entry['data'] ?? null : null;
    }

    /** Returns true if the cache file is missing or the TTL has expired. */
    public function isStale(string $key, int $ttl): bool
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return true;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return true;
        }

        /** @var array{fetched_at?: int}|null $entry */
        $entry = json_decode($raw, true);

        if (! is_array($entry) || ! isset($entry['fetched_at'])) {
            return true;
        }

        return (time() - (int) $entry['fetched_at']) > $ttl;
    }

    /** Returns true if the cache file does not exist at all. */
    public function isMissing(string $key): bool
    {
        return ! file_exists($this->path($key));
    }

    /** Stores data under the given key with the current timestamp. */
    public function put(string $key, mixed $data): void
    {
        $this->ensureDirectory();

        $entry = json_encode([
            'fetched_at' => time(),
            'data'       => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($entry === false) {
            throw new RuntimeException("Failed to JSON-encode cache entry for key '{$key}'.");
        }

        file_put_contents($this->path($key), $entry, LOCK_EX);
    }

    /** Deletes a single cache entry. */
    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** Deletes all cache files in the cache directory. */
    public function flush(): void
    {
        if (! is_dir($this->basePath)) {
            return;
        }

        $files = glob($this->basePath . '/*.json');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            unlink($file);
        }
    }

    /** Returns the timestamp of when the key was last fetched, or null. */
    public function fetchedAt(string $key): ?int
    {
        $path = $this->path($key);

        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        /** @var array{fetched_at?: int}|null $entry */
        $entry = json_decode($raw, true);

        return is_array($entry) && isset($entry['fetched_at']) ? (int) $entry['fetched_at'] : null;
    }

    private function path(string $key): string
    {
        // Dots become directory separators so 'pages.home' → pages/home.json
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $key) . '.json';

        return $this->basePath . DIRECTORY_SEPARATOR . $relative;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }
}
