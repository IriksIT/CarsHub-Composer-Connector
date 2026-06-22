<?php

declare(strict_types=1);

namespace CarsHub\Connector\Facades;

use CarsHub\Connector\CarsHubConnector;
use CarsHub\Connector\SyncResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static list<array<string, mixed>>         pages()
 * @method static array<string, mixed>|null          page(string $slug)
 * @method static list<array<string, mixed>>         events(string $type = 'upcoming')
 * @method static array<string, mixed>|null          eventDetail(int $id)
 * @method static list<array<string, mixed>>         members()
 * @method static list<array<string, mixed>>         cars()
 * @method static array<string, mixed>               stats()
 * @method static array<string, mixed>               createEvent(array<string, mixed> $data)
 * @method static array<string, mixed>               updateEvent(int $id, array<string, mixed> $data)
 * @method static void                               deleteEvent(int $id)
 * @method static SyncResult                         sync(?list<string> $types = null, bool $force = false)
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
