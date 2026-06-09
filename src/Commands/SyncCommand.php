<?php

declare(strict_types=1);

namespace CarsHub\Connector\Commands;

use CarsHub\Connector\CarsHubConnector;
use Illuminate\Console\Command;

final class SyncCommand extends Command
{
    protected $signature = 'carshub:sync
                            {--type=* : Data types to sync (pages, events, members, cars, stats). Omit to sync all.}
                            {--force  : Force refresh even if the cache is still fresh.}';

    protected $description = 'Sync crew data from the CarsHub API to the local JSON cache.';

    public function handle(CarsHubConnector $connector): int
    {
        $types = $this->option('type');
        $force = (bool) $this->option('force');

        /** @var list<string>|null $types */
        $types = $types !== [] ? $types : null;

        $label = $types !== null ? implode(', ', $types) : 'all';
        $this->info("Syncing CarsHub data ({$label})...");

        $result = $connector->sync($types, $force);

        foreach ($result->getSynced() as $key) {
            $this->line("  <fg=green>✓</> {$key}");
        }

        foreach ($result->getSkipped() as $key) {
            $this->line("  <fg=gray>- {$key} (skipped, cache is fresh)</>");
        }

        foreach ($result->getFailed() as $key => $reason) {
            $this->line("  <fg=red>✗</> {$key}: {$reason}");
        }

        if ($result->hasFailures()) {
            $this->warn('Sync completed with errors.');
            return self::FAILURE;
        }

        if ($result->isEmpty()) {
            $this->line('  Nothing to sync — all cache entries are fresh.');
        } else {
            $this->info('Sync complete.');
        }

        return self::SUCCESS;
    }
}
