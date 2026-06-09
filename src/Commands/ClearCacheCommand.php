<?php

declare(strict_types=1);

namespace CarsHub\Connector\Commands;

use CarsHub\Connector\CarsHubConnector;
use Illuminate\Console\Command;

final class ClearCacheCommand extends Command
{
    protected $signature = 'carshub:cache:clear';

    protected $description = 'Delete all locally cached CarsHub JSON files.';

    public function handle(CarsHubConnector $connector): int
    {
        $connector->clearCache();
        $this->info('CarsHub local cache cleared.');

        return self::SUCCESS;
    }
}
