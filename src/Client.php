<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final readonly class Client
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(string $url, string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient([
            'base_uri' => rtrim($url, '/'),
            'http_errors' => false
        ]);
    }

    public function ping(): void
    {
        $response = $this->httpClient->get('/api/v1/ping');
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to ping, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.ping-received') {
            throw new RuntimeException('Failed to ping');
        }
    }

    public function verifyApiToken(): void
    {
        $response = $this->httpClient->post(
            '/api/v1/verify-api-token',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to verify API token, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    public function writeEvents(array $events, array $preconditions = []): array
    {
        $requestBody = [
            'events' => $events,
        ];
        if (!empty($preconditions)) {
            $requestBody['preconditions'] = $preconditions;
        }

        $response = $this->httpClient->post(
            '/api/v1/write-events',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to write events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $writtenEvents = array_map(fn ($item) => new CloudEvent(
            $item['specversion'],
            $item['id'],
            new DateTimeImmutable($item['time']),
            $item['source'],
            $item['subject'],
            $item['type'],
            $item['datacontenttype'],
            $item['data'],
            $item['hash'],
            $item['predecessorhash'],
            $item['traceparent'] ?? null,
            $item['tracestate'] ?? null,
        ), $data);

        return $writtenEvents;
    }
}
