<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class ReadEventTypesTest extends TestCase
{
    use ClientTestTrait;

    public function testReadsNoEventTypesIfTheDatabaseIsEmpty(): void
    {
        $didReadEventTypes = false;

        foreach ($this->client->readEventTypes() as $event) {
            $didReadEventTypes = true;
        }

        $this->assertFalse($didReadEventTypes, 'Expected no events types to be read, but some were found.');
    }

    public function testReadsAllEventTypes(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test.foo',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test.bar',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventTypesRead = [];

        foreach ($this->client->readEventTypes() as $eventTypes) {
            $eventTypesRead[] = $eventTypes;
        }

        $this->assertCount(2, $eventTypesRead);
        $this->assertInstanceOf(EventType::class, $eventTypesRead[0]);
        $this->assertSame('io.eventsourcingdb.test.bar', $eventTypesRead[0]->eventType);
        $this->assertFalse($eventTypesRead[0]->isPhantom);
        $this->assertInstanceOf(EventType::class, $eventTypesRead[1]);
        $this->assertSame('io.eventsourcingdb.test.foo', $eventTypesRead[1]->eventType);
        $this->assertFalse($eventTypesRead[1]->isPhantom);
    }

    public function testSupportsReadingEventSchemas(): void
    {
        $eventType = 'io.eventsourcingdb.test';
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'number',
                ],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];

        $this->client->registerEventSchema($eventType, $schema);

        $eventTypesRead = [];

        foreach ($this->client->readEventTypes() as $eventTypes) {
            $eventTypesRead[] = $eventTypes;
        }

        $this->assertCount(1, $eventTypesRead);
        $this->assertInstanceOf(EventType::class, $eventTypesRead[0]);
        $this->assertSame('io.eventsourcingdb.test', $eventTypesRead[0]->eventType);
        $this->assertTrue($eventTypesRead[0]->isPhantom);
    }
}
