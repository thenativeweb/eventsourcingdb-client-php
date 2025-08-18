<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;

final readonly class Client
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(
        string $url,
        string $apiToken,
    ) {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient($url);
    }

    public function abortIn(float $seconds): void
    {
        $this->httpClient->abortIn($seconds);
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

        try {
            $data = $response->getStream()->getJsonData();
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                'Failed to ping: ' . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.ping-received') {
            throw new RuntimeException('Failed to ping');
        }
    }

    public function verifyApiToken(): void
    {
        $response = $this->httpClient->post(
            '/api/v1/verify-api-token',
            $this->apiToken,
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to verify API token, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        try {
            $data = $response->getStream()->getJsonData();
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                'Failed to verify API token: ' . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    public function writeEvents(array $events, array $preconditions = []): array
    {
        $requestBody = [
            'events' => $events,
        ];
        if ($preconditions !== []) {
            $requestBody['preconditions'] = $preconditions;
        }

        $response = $this->httpClient->post(
            '/api/v1/write-events',
            $this->apiToken,
            $requestBody,
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to write events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        try {
            $data = $response->getStream()->getJsonData();
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                'Failed to read events, after writing: ' . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        $writtenEvents = array_map(
            static fn (array $item): CloudEvent => new CloudEvent(
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
            ),
            $data,
        );

        return $writtenEvents;
    }

    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        $response = $this->httpClient->post(
            '/api/v1/read-events',
            $this->apiToken,
            [
                'subject' => $subject,
                'options' => $readEventsOptions,
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to read events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
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
        $response = $this->httpClient->post(
            '/api/v1/run-eventql-query',
            $this->apiToken,
            [
                'query' => $query,
            ],
        );

        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to run EventQL query, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
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

    public function observeEvents(string $subject, ObserveEventsOptions $observeEventsOptions): iterable
    {
        $response = $this->httpClient->post(
            '/api/v1/observe-events',
            $this->apiToken,
            [
                'subject' => $subject,
                'options' => $observeEventsOptions,
            ],
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to observe events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
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

    public function registerEventSchema(string $eventType, array $schema): void
    {
        $response = $this->httpClient->post(
            '/api/v1/register-event-schema',
            $this->apiToken,
            [
                'eventType' => $eventType,
                'schema' => $schema,
            ],
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to register event schema, got HTTP status code '%d', expected '200'",
                $status
            ));
        }
    }

    public function readSubjects(string $baseSubject): iterable
    {
        $response = $this->httpClient->post(
            '/api/v1/read-subjects',
            $this->apiToken,
            [
                'baseSubject' => $baseSubject,
            ],
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to read subjects, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'subject':
                    $subject = $eventLine->payload['subject'];
                    yield $subject;

                    break;
                case 'error':
                    throw new RuntimeException($eventLine->payload['error'] ?? 'unknown error');
                default:
                    throw new RuntimeException("Failed to handle unsupported line type {$eventLine->type}");
            }
        }
    }

    public function readEventTypes(): iterable
    {
        $response = $this->httpClient->post(
            '/api/v1/read-event-types',
            $this->apiToken,
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to read event types, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'eventType':
                    $eventType = new EventType(
                        $eventLine->payload['eventType'],
                        $eventLine->payload['isPhantom'],
                        $eventLine->payload['schema'] ?? [],
                    );
                    yield $eventType;

                    break;
                case 'error':
                    throw new RuntimeException($eventLine->payload['error'] ?? 'unknown error');
                default:
                    throw new RuntimeException("Failed to handle unsupported line type {$eventLine->type}");
            }
        }
    }

    public function readEventType(string $eventType): EventType
    {
        $response = $this->httpClient->post(
            '/api/v1/read-event-type',
            $this->apiToken,
            [
                'eventType' => $eventType,
            ],
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to read event type, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        try {
            $data = $response->getStream()->getJsonData();
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                'Failed to read event type: ' . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        return new EventType(
            $data['eventType'],
            $data['isPhantom'],
            $data['schema'] ?? [],
        );
    }
}
