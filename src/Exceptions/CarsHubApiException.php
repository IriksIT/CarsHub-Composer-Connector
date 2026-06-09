<?php

declare(strict_types=1);

namespace CarsHub\Connector\Exceptions;

use RuntimeException;

final class CarsHubApiException extends RuntimeException
{
    public static function httpError(string $url, int $status): self
    {
        return new self("CarsHub API returned HTTP {$status} for {$url}.");
    }

    public static function connectionFailed(string $url, string $reason): self
    {
        return new self("CarsHub API connection failed for {$url}: {$reason}");
    }

    public static function missingCredentials(): self
    {
        return new self('CARSHUB_API_KEY and CARSHUB_CREW_SLUG must be set in your .env file.');
    }
}
