<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;

/**
 * Speaks one API of EventSourcingDB. The client chooses the API that the
 * server offers on its first request, see TransportSelector.
 *
 * @internal
 */
interface Transport
{
    public function ping(): void;

    public function verifyApiToken(): void;

    /**
     * @return list<CloudEvent>
     */
    public function writeEvents(array $events, array $preconditions): array;

    /**
     * @return iterable<CloudEvent>
     */
    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable;

    public function runEventQlQuery(string $query): iterable;

    /**
     * @return iterable<CloudEvent>
     */
    public function observeEvents(string $subject, ObserveEventsOptions $observeEventsOptions): iterable;

    public function registerEventSchema(string $eventType, array $schema): void;

    /**
     * @return iterable<string>
     */
    public function readSubjects(string $subject, ReadSubjectsOptions $readSubjectsOptions): iterable;

    /**
     * @return iterable<EventType>
     */
    public function readEventTypes(): iterable;

    public function readEventType(string $eventType): EventType;
}
