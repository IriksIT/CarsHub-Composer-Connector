<?php

declare(strict_types=1);

namespace CarsHub\Connector;

final class SyncResult
{
    /** @var list<string> */
    private array $synced = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var array<string, string> */
    private array $failed = [];

    public function synced(string $key): void
    {
        $this->synced[] = $key;
    }

    public function skipped(string $key): void
    {
        $this->skipped[] = $key;
    }

    public function failed(string $key, string $reason): void
    {
        $this->failed[$key] = $reason;
    }

    /** @return list<string> */
    public function getSynced(): array
    {
        return $this->synced;
    }

    /** @return list<string> */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /** @return array<string, string> */
    public function getFailed(): array
    {
        return $this->failed;
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    public function isEmpty(): bool
    {
        return $this->synced === [] && $this->failed === [];
    }
}
