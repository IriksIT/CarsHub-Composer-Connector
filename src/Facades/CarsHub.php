<?php

declare(strict_types=1);

namespace CarsHub\Connector\Facades;

use CarsHub\Connector\CarsHubConnector;
use CarsHub\Connector\SyncResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed>|null          page(string $slug)
 * @method static list<array<string, mixed>>         events(string $type = 'upcoming')
 * @method static list<array<string, mixed>>         members()
 * @method static list<array<string, mixed>>         cars()
 * @method static array<string, mixed>               stats()
 * @method static SyncResult                         sync(?array $types = null, bool $force = false)
 * @method static bool                               hasEmptyCache()
 * @method static array<string, mixed>               cacheStatus()
 * @method static void                               clearCache()
 *
 * @see CarsHubConnector
 */
final class CarsHub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CarsHubConnector::class;
    }
}
