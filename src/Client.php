<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Closure;
use Thenativeweb\Eventsourcingdb\Api\Preconditions;
use Thenativeweb\Eventsourcingdb\Api\ProbingHttpClient;
use Thenativeweb\Eventsourcingdb\Api\Transport;
use Thenativeweb\Eventsourcingdb\Api\TransportSelector;
use Thenativeweb\Eventsourcingdb\Stream\CurlMultiHandler;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;

/**
 * Talks to EventSourcingDB, through the v2 API if the server offers it, and
 * through the v1 API otherwise. Creating a client does not send a request;
 * the first request of a client finds out which API the server offers.
 */
final readonly class Client
{
    private HttpClient $httpClient;
    private TransportSelector $transportSelector;

    /**
     * @param float $stallTimeout the seconds after which a request fails if nothing at all has arrived from the server, neither data nor a heartbeat, counted from sending the request
     */
    public function __construct(
        string $url,
        string $apiToken,
        float $stallTimeout = CurlMultiHandler::DEFAULT_STALL_TIMEOUT,
    ) {
        $this->httpClient = new HttpClient($url, $stallTimeout);
        $this->transportSelector = new TransportSelector($this->httpClient, $apiToken, new ProbingHttpClient($url, $stallTimeout));
    }

    /**
     * Aborts the stream being read, or, if there is none, the next request,
     * once the given seconds, counted from now, are over. An abort time of
     * zero or less sets none.
     */
    public function abortIn(float $seconds): void
    {
        $this->httpClient->abortIn($seconds);
    }

    public function ping(): void
    {
        $this->transport()
            ->ping();
    }

    public function verifyApiToken(): void
    {
        $this->transport()
            ->verifyApiToken();
    }

    /**
     * @return list<CloudEvent>
     */
    public function writeEvents(array $events, array $preconditions = []): array
    {
        return $this->transport(isV2ApiNeeded: Preconditions::includeRecursiveOne($preconditions))
            ->writeEvents($events, $preconditions);
    }

    /**
     * @return iterable<CloudEvent>
     */
    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        yield from $this->readStream(fn (): iterable => $this->transport()
            ->readEvents($subject, $readEventsOptions));
    }

    public function runEventQlQuery(string $query): iterable
    {
        yield from $this->readStream(fn (): iterable => $this->transport()
            ->runEventQlQuery($query));
    }

    /**
     * @return iterable<CloudEvent>
     */
    public function observeEvents(string $subject, ObserveEventsOptions $observeEventsOptions): iterable
    {
        yield from $this->readStream(fn (): iterable => $this->transport()
            ->observeEvents($subject, $observeEventsOptions));
    }

    public function registerEventSchema(string $eventType, array $schema): void
    {
        $this->transport()
            ->registerEventSchema($eventType, $schema);
    }

    /**
     * @return iterable<string>
     */
    public function readSubjects(string $subject, ReadSubjectsOptions $readSubjectsOptions): iterable
    {
        yield from $this->readStream(fn (): iterable => $this->transport()
            ->readSubjects($subject, $readSubjectsOptions));
    }

    /**
     * @return iterable<EventType>
     */
    public function readEventTypes(): iterable
    {
        yield from $this->readStream(fn (): iterable => $this->transport()
            ->readEventTypes());
    }

    public function readEventType(string $eventType): EventType
    {
        return $this->transport()
            ->readEventType($eventType);
    }

    private function transport(bool $isV2ApiNeeded = false): Transport
    {
        return $this->transportSelector->getTransport($isV2ApiNeeded);
    }

    /**
     * Yields the items of a stream, and ends it without an exception once
     * abortIn aborts it before its answer arrives, as a stream that abortIn
     * aborts while it is being read ends.
     *
     * @param Closure(): iterable $stream
     */
    private function readStream(Closure $stream): iterable
    {
        try {
            yield from $stream();
        } catch (RequestAbortedException) {
            return;
        }
    }
}
