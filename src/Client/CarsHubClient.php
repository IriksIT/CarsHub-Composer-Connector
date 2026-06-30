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
        private readonly ?string $importKey = null,
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
        /** @var array<string, mixed> */
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
        /** @var array<string, mixed> */
        return $this->get("crews/{$this->crewSlug}/events/{$eventId}");
    }

    /**
     * Returns the URL to a QR code image (PNG) for the given event.
     * The QR code links through CarsHub for scan tracking, then redirects to the crew website.
     * Returns null if the crew's QR codes module is not active or no website URL is configured.
     *
     * @return string|null
     */
    public function getEventQrCodeUrl(int $eventId): ?string
    {
        $detail = $this->getEventDetail($eventId);

        /** @var string|null */
        return isset($detail['qr_code_url']) && is_string($detail['qr_code_url'])
            ? $detail['qr_code_url']
            : null;
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
        /** @var array<string, mixed> */
        return $this->get("crews/{$this->crewSlug}/stats");
    }

    // -------------------------------------------------------------------------
    // Event Import API (requires event_import_key)
    // -------------------------------------------------------------------------

    /**
     * Create an event on a system-managed crew via the import API.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws CarsHubApiException
     */
    public function createEvent(array $data): array
    {
        $url = $this->url("crews/{$this->crewSlug}/import/events");

        try {
            $response = $this->importPending()->post($url, $data);
        } catch (ConnectionException $e) {
            throw CarsHubApiException::connectionFailed($url, $e->getMessage());
        }

        $this->assertSuccessful($response, $url);

        /** @var array<string, mixed> */
        return $this->unwrapData($response);
    }

    /**
     * Replace all fields of an existing imported event.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws CarsHubApiException
     */
    public function updateEvent(int $id, array $data): array
    {
        $url = $this->url("crews/{$this->crewSlug}/import/events/{$id}");

        try {
            $response = $this->importPending()->put($url, $data);
        } catch (ConnectionException $e) {
            throw CarsHubApiException::connectionFailed($url, $e->getMessage());
        }

        $this->assertSuccessful($response, $url);

        /** @var array<string, mixed> */
        return $this->unwrapData($response);
    }

    /**
     * Delete an imported event permanently.
     *
     * @throws CarsHubApiException
     */
    public function deleteEvent(int $id): void
    {
        $url = $this->url("crews/{$this->crewSlug}/import/events/{$id}");

        try {
            $response = $this->importPending()->delete($url);
        } catch (ConnectionException $e) {
            throw CarsHubApiException::connectionFailed($url, $e->getMessage());
        }

        $this->assertSuccessful($response, $url);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
        $url = $this->url($endpoint);

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

        return $this->unwrapData($response);
    }

    private function assertSuccessful(Response $response, string $url): void
    {
        if (! $response->successful()) {
            throw CarsHubApiException::httpError($url, $response->status());
        }
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    /** @return \Illuminate\Http\Client\PendingRequest */
    private function importPending(): \Illuminate\Http\Client\PendingRequest
    {
        if ($this->importKey === null || $this->importKey === '') {
            throw CarsHubApiException::missingImportKey();
        }

        return $this->http
            ->withToken($this->importKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    /** @return array<mixed> */
    private function unwrapData(Response $response): array
    {
        /** @var array<mixed>|null $json */
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        /** @var array<mixed> */
        return array_key_exists('data', $json) ? $json['data'] : $json;
    }
}
