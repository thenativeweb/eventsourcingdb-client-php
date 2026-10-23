<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Closure;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;
use Thenativeweb\Eventsourcingdb\Stream\RawJson;
use Thenativeweb\Eventsourcingdb\Stream\ReadEventLine;
use Thenativeweb\Eventsourcingdb\Stream\Response;

/**
 * Speaks the v2 API, which EventSourcingDB offers since 1.3. Every answer is
 * an item {type, data}, either on its own or as a line of a stream. A stream
 * that ends ends with an item of type end, so a stream that breaks off before
 * that item has failed. The server reports an error of the request as an item
 * of type error, with the status 200, and fails with another status only if
 * it cannot answer the request at all.
 *
 * @internal
 */
final readonly class V2Transport implements Transport
{
    /**
     * The statuses with which the server refuses a write before storing
     * anything of it.
     */
    private const REFUSING_STATUSES = [400, 401, 408, 413, 429, 503];

    /**
     * @param Closure(self): void $forgetApi makes the client forget the API that the server offers, so that its next request asks again, see TransportSelector
     */
    public function __construct(
        private HttpClient $httpClient,
        private string $apiToken,
        private Closure $forgetApi,
    ) {
    }

    public function ping(): void
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->get('/api/v2/system/ping'),
            'Failed to ping',
        );

        $event = $this->readEvent($response, 'Failed to ping');

        if (($event['type'] ?? null) !== 'io.eventsourcingdb.api.ping-received') {
            throw new RuntimeException('Failed to ping');
        }
    }

    public function verifyApiToken(): void
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->post(
                '/api/v2/system/verify-token',
                $this->apiToken,
            ),
            'Failed to verify API token',
        );

        $event = $this->readEvent($response, 'Failed to verify API token');

        if (($event['type'] ?? null) !== 'io.eventsourcingdb.api.token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    public function writeEvents(array $events, array $preconditions): array
    {
        return WriteOutcome::guard(
            $this->httpClient,
            fn (): array => $this->sendEvents($events, $preconditions),
            $this->isRefusal(...),
            'It is unknown whether the events were written',
        );
    }

    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/read-events',
                $this->apiToken,
                [
                    'subject' => $subject,
                    'options' => $readEventsOptions,
                ],
            ),
            'Failed to read events',
        );

        foreach ($this->readItems($response, 'Failed to read events', endsWithEndItem: true) as $item) {
            if ($item->type !== 'event') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            yield $this->createCloudEvent($item);
        }
    }

    public function runEventQlQuery(string $query): iterable
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/run-eventql-query',
                $this->apiToken,
                [
                    'query' => $query,
                ],
            ),
            'Failed to run EventQL query',
        );

        foreach ($this->readItems($response, 'Failed to run EventQL query', endsWithEndItem: true) as $item) {
            if ($item->type !== 'row') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            // The v2 API wraps the value of a row, which may be anything that
            // JSON can hold, so that every row has the same shape.
            yield $item->payload['value'] ?? null;
        }
    }

    public function observeEvents(string $subject, ObserveEventsOptions $observeEventsOptions): iterable
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/observe-events',
                $this->apiToken,
                [
                    'subject' => $subject,
                    'options' => $observeEventsOptions,
                ],
            ),
            'Failed to observe events',
        );

        foreach ($this->readItems($response, 'Failed to observe events', endsWithEndItem: false) as $item) {
            if ($item->type !== 'event') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            yield $this->createCloudEvent($item);
        }
    }

    public function registerEventSchema(string $eventType, array $schema): void
    {
        WriteOutcome::guard(
            $this->httpClient,
            function () use ($eventType, $schema): void {
                $response = $this->send(
                    fn (): Response => $this->httpClient->post(
                        '/api/v2/data/register-event-schema',
                        $this->apiToken,
                        [
                            'type' => $eventType,
                            'schema' => $schema,
                        ],
                    ),
                    'Failed to register event schema',
                );

                // The registration is reported within the stream, so it is read
                // to its end.
                foreach ($this->readItems($response, 'Failed to register event schema', endsWithEndItem: true, isAnswerToWrite: true) as $item) {
                    if ($item->type !== 'event') {
                        throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
                    }
                }
            },
            $this->isRefusal(...),
            'It is unknown whether the event schema was registered',
        );
    }

    public function readSubjects(string $subject, ReadSubjectsOptions $readSubjectsOptions): iterable
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/read-subjects',
                $this->apiToken,
                [
                    'subject' => $subject,
                    'options' => [
                        'recursive' => $readSubjectsOptions->recursive,
                    ],
                ],
            ),
            'Failed to read subjects',
        );

        foreach ($this->readItems($response, 'Failed to read subjects', endsWithEndItem: true) as $item) {
            if ($item->type !== 'subject') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            yield $item->payload['subject'];
        }
    }

    public function readEventTypes(): iterable
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/read-event-types',
                $this->apiToken,
            ),
            'Failed to read event types',
        );

        foreach ($this->readItems($response, 'Failed to read event types', endsWithEndItem: true) as $item) {
            if ($item->type !== 'eventType') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            yield $this->createEventType($item->payload);
        }
    }

    public function readEventType(string $eventType): EventType
    {
        $response = $this->send(
            fn (): Response => $this->httpClient->query(
                '/api/v2/data/read-event-type',
                $this->apiToken,
                [
                    'type' => $eventType,
                ],
            ),
            'Failed to read event type',
        );

        $item = $this->readItem($response, 'Failed to read event type');
        if ($item['type'] !== 'eventType' || !is_array($item['data'] ?? null)) {
            throw new RuntimeException('Failed to read event type, since the server answered with an item of type ' . var_export($item['type'], true) . '.');
        }

        return $this->createEventType($item['data']);
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

        $response = $this->send(
            fn (): Response => $this->httpClient->post(
                '/api/v2/data/write-events',
                $this->apiToken,
                $requestBody,
            ),
            'Failed to write events',
        );

        $writtenEvents = [];
        foreach ($this->readItems($response, 'Failed to write events', endsWithEndItem: true, isAnswerToWrite: true) as $item) {
            if ($item->type !== 'event') {
                throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
            }

            $writtenEvents[] = $this->createCloudEvent($item);
        }

        return $writtenEvents;
    }

    /**
     * Tells whether the server refused a write explicitly, which it does with
     * an error in the body of its answer, unless the error is that storing the
     * write failed, or with one of the statuses that it answers before storing
     * anything.
     */
    private function isRefusal(DbApiException $dbApiException): bool
    {
        if ($dbApiException->status === 200) {
            return $dbApiException->errorCode !== ErrorCode::STORE_FAILED;
        }

        return in_array($dbApiException->status, self::REFUSING_STATUSES, true);
    }

    /**
     * Yields the items of a stream that carry data, and skips heartbeats.
     * It throws the error that an item of type error reports, and an error if
     * the stream breaks off: a stream that ends with an item of type end
     * before that item, and an observation, which the server never ends, at
     * all. A stream that abortIn ends simply ends, unless it is the answer to
     * a write, whose outcome it leaves open, which it reports with a
     * RequestAbortedException, as any other request that is aborted.
     *
     * @return iterable<ReadEventLine>
     */
    private function readItems(Response $response, string $scope, bool $endsWithEndItem, bool $isAnswerToWrite = false): iterable
    {
        foreach (NdJson::readStream($response->getStream(), 'data') as $item) {
            switch ($item->type) {
                case 'heartbeat':
                    break;
                case 'error':
                    throw Responses::createErrorFromItem($item->payload, $scope);
                case 'end':
                    if (!$endsWithEndItem) {
                        throw new RuntimeException("Failed to handle unsupported line type {$item->type}");
                    }

                    return;
                default:
                    yield $item;
            }
        }

        if ($this->httpClient->hasLastStreamBeenAborted()) {
            if ($isAnswerToWrite) {
                throw new RequestAbortedException();
            }

            return;
        }

        throw Responses::createErrorOfBrokenStream($scope);
    }

    /**
     * Reads an answer that consists of a single item, and throws the error
     * that it reports, if it is of type error.
     */
    private function readItem(Response $response, string $scope): array
    {
        try {
            $item = $response->getStream()
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

        if (!is_string($item['type'] ?? null)) {
            throw new RuntimeException("{$scope}, since the answer of the server names no type.");
        }

        if ($item['type'] === 'error') {
            throw Responses::createErrorFromItem($item['data'] ?? null, $scope);
        }

        return $item;
    }

    /**
     * Reads an answer that consists of a single event, and returns the event.
     */
    private function readEvent(Response $response, string $scope): array
    {
        $item = $this->readItem($response, $scope);
        if ($item['type'] !== 'event' || !is_array($item['data'] ?? null)) {
            throw new RuntimeException($scope);
        }

        return $item['data'];
    }

    private function createCloudEvent(ReadEventLine $readEventLine): CloudEvent
    {
        return Responses::createCloudEvent($readEventLine->payload, RawJson::getMembers($readEventLine->line)['data'] ?? null);
    }

    private function createEventType(array $data): EventType
    {
        return new EventType(
            $data['type'],
            $data['hasEvents'],
            $data['schema'] ?? [],
        );
    }

    /**
     * Sends a request, and returns its answer if the server answered it with
     * 200, and throws otherwise.
     *
     * A server that answers a route of the v2 API with 404 or 405 no longer
     * offers it, e.g. since it was rolled back to a version without it, so
     * the client forgets the API, and its next request asks again, while this
     * one fails as it would otherwise. As when asking, the status counts
     * whoever answers, also another server in front of the database, so it
     * counts before the answer is checked, even if the HTTP client refuses
     * the answer for its content type.
     *
     * @param Closure(): Response $request the request, which sends exactly one with the HTTP client
     */
    private function send(Closure $request, string $scope): Response
    {
        try {
            $response = $request();
        } finally {
            if (TransportSelector::meansNoV2Api($this->httpClient->getLastStatusCode())) {
                ($this->forgetApi)($this);
            }
        }

        $this->throwIfNotSuccessful($response, $scope);

        return $response;
    }

    private function throwIfNotSuccessful(Response $response, string $scope): void
    {
        Responses::throwIfNotFromEventSourcingDb($response);

        if ($response->getStatusCode() === 200) {
            return;
        }

        throw Responses::createErrorFromStatus($response, $scope);
    }
}
