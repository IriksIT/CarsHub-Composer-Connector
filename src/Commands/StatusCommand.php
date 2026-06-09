<?php

declare(strict_types=1);

namespace CarsHub\Connector\Commands;

use CarsHub\Connector\CarsHubConnector;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'carshub:status';

    protected $description = 'Show the age and freshness of each cached CarsHub data type.';

    public function handle(CarsHubConnector $connector): int
    {
        $status = $connector->cacheStatus();

        $rows = [];

        foreach ($status as $entry) {
            $fetchedAt = $entry['fetched_at'] !== null
                ? date('Y-m-d H:i:s', $entry['fetched_at']) . ' (' . $this->humanAgo($entry['fetched_at']) . ')'
                : '—';

            $rows[] = [
                $entry['key'],
                $fetchedAt,
                $entry['stale'] ? '<fg=red>stale</>' : '<fg=green>fresh</>',
            ];
        }

        $this->table(['Cache key', 'Last fetched', 'Status'], $rows);

        return self::SUCCESS;
    }

    private function humanAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return "{$diff}s ago";
        }

        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return "{$m}m ago";
        }

        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return "{$h}h ago";
        }

        $d = (int) floor($diff / 86400);
        return "{$d}d ago";
    }
}
