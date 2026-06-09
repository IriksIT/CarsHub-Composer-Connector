<?php

declare(strict_types=1);

namespace CarsHub\Connector\Jobs;

use CarsHub\Connector\CarsHubConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param list<string>|null $types  null = sync all
     */
    public function __construct(
        private readonly ?array $types = null,
        private readonly bool $force = false,
    ) {}

    public function handle(CarsHubConnector $connector): void
    {
        $result = $connector->sync($this->types, $this->force);

        if ($result->hasFailures()) {
            Log::warning('CarsHub background sync had failures.', [
                'failed' => $result->getFailed(),
            ]);
        }

        Log::debug('CarsHub background sync complete.', [
            'synced'  => $result->getSynced(),
            'skipped' => $result->getSkipped(),
            'failed'  => array_keys($result->getFailed()),
        ]);
    }
}
