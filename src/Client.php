<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

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
        $this->transportSelector = new TransportSelector($this->httpClient, $apiToken);
    }

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
        return $this->transport()
            ->writeEvents($events, $preconditions);
    }

    /**
     * @return iterable<CloudEvent>
     */
    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        yield from $this->transport()
            ->readEvents($subject, $readEventsOptions);
    }

    public function runEventQlQuery(string $query): iterable
    {
        yield from $this->transport()
            ->runEventQlQuery($query);
    }

    /**
     * @return iterable<CloudEvent>
     */
    public function observeEvents(string $subject, ObserveEventsOptions $observeEventsOptions): iterable
    {
        yield from $this->transport()
            ->observeEvents($subject, $observeEventsOptions);
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
        yield from $this->transport()
            ->readSubjects($subject, $readSubjectsOptions);
    }

    /**
     * @return iterable<EventType>
     */
    public function readEventTypes(): iterable
    {
        yield from $this->transport()
            ->readEventTypes();
    }

    public function readEventType(string $eventType): EventType
    {
        return $this->transport()
            ->readEventType($eventType);
    }

    private function transport(): Transport
    {
        return $this->transportSelector->getTransport();
    }
}
