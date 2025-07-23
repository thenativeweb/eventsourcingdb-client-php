<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;

final readonly class Client
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(string $url, string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient([
            'base_uri' => rtrim($url, '/'),
            'http_errors' => false,
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

        $body = $response->getBody()->getContents();
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

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    public function writeEvents(array $events, array $preconditions = []): iterable
    {
        $requestBody = [
            'events' => $events,
        ];
        if ($preconditions !== []) {
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

        $body = $response->getBody()->getContents();
        if ($body === '') {
            return;
        }

        if (!json_validate($body)) {
            throw new RuntimeException('Failed to read events.');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Failed to read events, expected an array.');
        }

        foreach ($data as $item) {
            $cloudEvent = new CloudEvent(
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
            );
            yield $cloudEvent;
        }
    }

    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        $requestBody = [
            'subject' => $subject,
            'options' => $readEventsOptions,
        ];

        $response = $this->httpClient->post(
            '/api/v1/read-events',
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
                "Failed to read events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getBody()) as $eventLine) {
            switch ($eventLine->type) {
                case 'event':
                    $cloudEvent = new CloudEvent(
                        $eventLine->payload['specversion'],
                        $eventLine->payload['id'],
                        new DateTimeImmutable($eventLine->payload['time']),
                        $eventLine->payload['source'],
                        $eventLine->payload['subject'],
                        $eventLine->payload['type'],
                        $eventLine->payload['datacontenttype'],
                        $eventLine->payload['data'],
                        $eventLine->payload['hash'],
                        $eventLine->payload['predecessorhash'],
                        $eventLine->payload['traceparent'] ?? null,
                        $eventLine->payload['tracestate'] ?? null,
                    );
                    yield $cloudEvent;

                    break;
                case 'error':
                    throw new RuntimeException($eventLine->payload['error'] ?? 'unknown error');
                default:
                    throw new RuntimeException("Failed to handle unsupported line type {$eventLine->type}");
            }
        }
    }

    public function runEventQlQuery(string $query): iterable
    {
        $requestBody = [
            'query' => $query,
        ];

        $response = $this->httpClient->post(
            '/api/v1/run-eventql-query',
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
                "Failed to run EventQL query, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getBody()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'row':
                    $row = $eventLine->payload;
                    yield $row;

                    break;
                case 'error':
                    throw new RuntimeException($eventLine->payload['error'] ?? 'unknown error');
                default:
                    throw new RuntimeException("Failed to handle unsupported line type {$eventLine->type}");
            }
        }
    }
}
