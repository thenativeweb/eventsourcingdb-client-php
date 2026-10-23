<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\IncompatibleServerException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;
use Thenativeweb\Eventsourcingdb\Stream\RawJson;
use Thenativeweb\Eventsourcingdb\Stream\Response;
use Thenativeweb\Eventsourcingdb\Stream\Stream;

/**
 * Speaks the v1 API, which servers before EventSourcingDB 1.3 offer.
 *
 * @internal
 */
final readonly class V1Transport implements Transport
{
    public function __construct(
        private HttpClient $httpClient,
        private string $apiToken,
    ) {
    }

    public function ping(): void
    {
        $response = $this->httpClient->get('/api/v1/ping');

        $this->throwIfNotSuccessful($response, 'Failed to ping');

        $data = $this->readJson($response, 'Failed to ping');

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

        $this->throwIfNotSuccessful($response, 'Failed to verify API token');

        $data = $this->readJson($response, 'Failed to verify API token');

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    /**
     * The v1 API cannot check a recursive precondition, so a write with one
     * fails before anything is sent, rather than check less than was asked.
     * TransportSelector asks which API the server offers right before such a
     * write, also if the client remembered it, so the write gets here only if
     * the server offers the v1 API only by then. It fails outside of the
     * guard, which would judge that failure by an earlier request, and report
     * it as an unknown outcome. As a request that fails, it uses up an abort
     * time that waits for it, rather than leave it to a later request.
     */
    public function writeEvents(array $events, array $preconditions): array
    {
        if (Preconditions::includeRecursiveOne($preconditions)) {
            $this->httpClient->useUpAbortTimeOfNextRequest();

            throw new IncompatibleServerException('The server does not offer the v2 API, which a recursive precondition needs.');
        }

        return WriteOutcome::guard(
            $this->httpClient,
            fn (): array => $this->sendEvents($events, Preconditions::removeOptions($preconditions)),
            $this->isRefusal(...),
            'It is unknown whether the events were written',
        );
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

        $this->throwIfNotSuccessful($response, 'Failed to read events', V1ErrorCodes::READ_EVENTS);

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'event':
                    $cloudEvent = Responses::createCloudEvent($eventLine->payload, RawJson::getMembers($eventLine->line)['payload'] ?? null);
                    yield $cloudEvent;

                    break;
                case 'error':
                    throw Responses::createErrorFromV1Item($eventLine->payload, 'Failed to read events');
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

        $this->throwIfNotSuccessful($response, 'Failed to run EventQL query');

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'row':
                    $row = $eventLine->payload;
                    yield $row;

                    break;
                case 'error':
                    throw Responses::createErrorFromV1Item($eventLine->payload, 'Failed to run EventQL query');
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

        $this->throwIfNotSuccessful($response, 'Failed to observe events');

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'event':
                    $cloudEvent = Responses::createCloudEvent($eventLine->payload, RawJson::getMembers($eventLine->line)['payload'] ?? null);
                    yield $cloudEvent;

                    break;
                case 'error':
                    throw Responses::createErrorFromV1Item($eventLine->payload, 'Failed to observe events');
                default:
                    throw new RuntimeException("Failed to handle unsupported line type {$eventLine->type}");
            }
        }

        // The server never ends an observation, other than with an error, so
        // one that ends otherwise has broken off, unless abortIn ended it.
        if (!$this->httpClient->hasLastStreamBeenAborted()) {
            throw Responses::createErrorOfBrokenStream('Failed to observe events');
        }
    }

    public function registerEventSchema(string $eventType, array $schema): void
    {
        WriteOutcome::guard(
            $this->httpClient,
            function () use ($eventType, $schema): void {
                $response = $this->httpClient->post(
                    '/api/v1/register-event-schema',
                    $this->apiToken,
                    [
                        'eventType' => $eventType,
                        'schema' => $schema,
                    ],
                );

                $this->throwIfNotSuccessful($response, 'Failed to register event schema', V1ErrorCodes::REGISTER_EVENT_SCHEMA);

                // The response is not needed, so its connection is closed rather
                // than kept open until the next request.
                $response->getStream()
                    ->close();
            },
            $this->isRefusal(...),
            'It is unknown whether the event schema was registered',
        );
    }

    /**
     * The v1 API always lists the subjects below the given subject as well,
     * so to list the given subject alone, the listing is narrowed down to it.
     */
    public function readSubjects(string $subject, ReadSubjectsOptions $readSubjectsOptions): iterable
    {
        $response = $this->httpClient->post(
            '/api/v1/read-subjects',
            $this->apiToken,
            [
                'baseSubject' => $subject,
            ],
        );

        $this->throwIfNotSuccessful($response, 'Failed to read subjects');

        // The server lists the subject as it normalizes it, i.e. with a single
        // leading slash and no trailing one, as the v2 API reads it.
        $normalizedSubject = '/' . trim($subject, '/');

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'subject':
                    $subjectRead = $eventLine->payload['subject'];

                    if ($readSubjectsOptions->recursive) {
                        yield $subjectRead;

                        break;
                    }

                    // The listing holds every subject once, so it is done once
                    // the given subject has been found.
                    if ($subjectRead === $normalizedSubject) {
                        yield $subjectRead;

                        return;
                    }

                    break;
                case 'error':
                    throw Responses::createErrorFromV1Item($eventLine->payload, 'Failed to read subjects');
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

        $this->throwIfNotSuccessful($response, 'Failed to read event types');

        foreach (NdJson::readStream($response->getStream()) as $eventLine) {
            switch ($eventLine->type) {
                case 'heartbeat':
                    break;
                case 'eventType':
                    $eventType = $this->createEventType($eventLine->payload);
                    yield $eventType;

                    break;
                case 'error':
                    throw Responses::createErrorFromV1Item($eventLine->payload, 'Failed to read event types');
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

        $this->throwIfNotSuccessful($response, 'Failed to read event type', V1ErrorCodes::READ_EVENT_TYPE);

        return $this->createEventType($this->readJson($response, 'Failed to read event type'));
    }

    /**
     * @return list<CloudEvent>
     */
    private function sendEvents(array $events, array $preconditions): array
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

        $this->throwIfNotSuccessful($response, 'Failed to write events', V1ErrorCodes::WRITE_EVENTS);

        try {
            $contents = $response->getStream()
                ->getContents();

            // The server answers a write with the events it wrote, so an empty
            // answer is not one.
            if ($contents === '') {
                throw new RuntimeException('the response is empty');
            }

            $data = Stream::decodeJson($contents);
            $rawEvents = RawJson::getElements($contents);
        } catch (ConnectionStalledException|RequestAbortedException $exception) {
            // A stalled connection and an aborted request keep their own type,
            // so that they can be told apart from a response that cannot be
            // read.
            throw $exception;
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                'Failed to read events, after writing: ' . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        return array_map(
            Responses::createCloudEvent(...),
            $data,
            $rawEvents,
        );
    }

    /**
     * Tells whether the server refused a write explicitly, which it does with
     * a 4xx status, or with 503 or 507, rather than failing to store it.
     */
    private function isRefusal(DbApiException $dbApiException): bool
    {
        $status = $dbApiException->status;

        return ($status >= 400 && $status < 500) || $status === 503 || $status === 507;
    }

    private function createEventType(array $data): EventType
    {
        return new EventType(
            $data['eventType'],
            !$data['isPhantom'],
            $data['schema'] ?? [],
        );
    }

    private function throwIfNotSuccessful(Response $response, string $scope, string $call = V1ErrorCodes::OTHER): void
    {
        Responses::throwIfNotFromEventSourcingDb($response);

        if ($response->getStatusCode() === 200) {
            return;
        }

        $description = Responses::readErrorText($response);

        throw Responses::createError(
            $response,
            $scope,
            V1ErrorCodes::getCode($call, $response->getStatusCode(), $description),
            $description,
        );
    }

    private function readJson(Response $response, string $scope): array
    {
        try {
            return $response->getStream()
                ->getJsonData();
        } catch (ConnectionStalledException|RequestAbortedException $exception) {
            // A stalled connection and an aborted request keep their own type,
            // so that they can be told apart from a response that cannot be
            // read.
            throw $exception;
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                "{$scope}: " . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }
    }
}
