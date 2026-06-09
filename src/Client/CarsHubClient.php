<?php

declare(strict_types=1);

namespace CarsHub\Connector\Client;

use CarsHub\Connector\Exceptions\CarsHubApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

final class CarsHubClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $crewSlug,
        private readonly int $timeout,
    ) {
        if ($this->apiKey === '' || $this->crewSlug === '') {
            throw CarsHubApiException::missingCredentials();
        }
    }

    /** @return list<array<string, mixed>> */
    public function getPages(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->get("crews/{$this->crewSlug}/pages");
    }

    /** @return array<string, mixed> */
    public function getPage(string $page): array
    {
        return $this->get("crews/{$this->crewSlug}/pages/{$page}");
    }

    /**
     * @param 'upcoming'|'past' $type
     * @return list<array<string, mixed>>
     */
    public function getEvents(string $type = 'upcoming'): array
    {
        /** @var list<array<string, mixed>> */
        return $this->get("crews/{$this->crewSlug}/events/{$type}");
    }

    /** @return array<string, mixed> */
    public function getEventDetail(int $eventId): array
    {
        return $this->get("crews/{$this->crewSlug}/events/{$eventId}");
    }

    /** @return list<array<string, mixed>> */
    public function getMembers(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->get("crews/{$this->crewSlug}/members");
    }

    /** @return list<array<string, mixed>> */
    public function getCars(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->get("crews/{$this->crewSlug}/cars");
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->get("crews/{$this->crewSlug}/stats");
    }

    /**
     * Performs a GET request and unwraps the `data` envelope returned by every
     * CarsHub API endpoint.  Raw responses look like `{"data": [...]}` or
     * `{"data": {...}}` — callers always receive the inner value directly.
     *
     * @return array<mixed>
     * @throws CarsHubApiException
     */
    private function get(string $endpoint): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            throw CarsHubApiException::connectionFailed($url, $e->getMessage());
        }

        $this->assertSuccessful($response, $url);

        /** @var array<mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        /** @var array<mixed> */
        return array_key_exists('data', $json) ? $json['data'] : $json;
    }

    private function assertSuccessful(Response $response, string $url): void
    {
        if (! $response->successful()) {
            throw CarsHubApiException::httpError($url, $response->status());
        }
    }
}
