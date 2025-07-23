<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Bound;
use Thenativeweb\Eventsourcingdb\BoundType;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\Order;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadFromLatestEvent;
use Thenativeweb\Eventsourcingdb\ReadIfEventIsMissing;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class ReadEventsTest extends TestCase
{
    use ClientTestTrait;

    public function testReadsNoEventsIfTheDatabaseIsEmpty(): void
    {
        $readEventsOptions = new ReadEventsOptions(true);
        $didReadEvents = false;

        foreach ($this->client->readEvents('/', $readEventsOptions) as $readEvent) {
            $didReadEvents = true;
        }

        $this->assertFalse($didReadEvents, 'Expected no events to be read, but some were found.');
    }

    public function testReadsAllEventsFromTheGivenSubject(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(false);

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(2, $eventsRead);
    }

    public function testReadsRecursively(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(true);

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(2, $eventsRead);
    }

    public function testReadsChronologically(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            order: Order::CHRONOLOGICAL,
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(2, $eventsRead);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        $this->assertSame('0', $eventsRead[0]->id);
        $this->assertSame(23, $eventsRead[0]->data['value']);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[1]);
        $this->assertSame('1', $eventsRead[1]->id);
        $this->assertSame(42, $eventsRead[1]->data['value']);
    }

    public function testReadsAntiChronologically(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            order: Order::ANTICHRONOLOGICAL,
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(2, $eventsRead);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        $this->assertSame('1', $eventsRead[0]->id);
        $this->assertSame(42, $eventsRead[0]->data['value']);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[1]);
        $this->assertSame('0', $eventsRead[1]->id);
        $this->assertSame(23, $eventsRead[1]->data['value']);
    }

    public function testReadsWithLowerBound(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            lowerBound: new Bound('1', BoundType::INCLUSIVE),
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(1, $eventsRead);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        $this->assertSame('1', $eventsRead[0]->id);
        $this->assertSame(42, $eventsRead[0]->data['value']);
    }

    public function testReadsWithUpperBound(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        iterator_count($this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            upperBound: new Bound('0', BoundType::INCLUSIVE),
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(1, $eventsRead);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        $this->assertSame('0', $eventsRead[0]->id);
        $this->assertSame(23, $eventsRead[0]->data['value']);
    }

    public function testReadsFromLatestEvent(): void
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

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            fromLatestEvent: new ReadFromLatestEvent(
                subject: '/test',
                type: 'io.eventsourcingdb.test.bar',
                ifEventIsMissing: ReadIfEventIsMissing::READ_EVERYTHING,
            ),
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(1, $eventsRead);
        $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        $this->assertSame('1', $eventsRead[0]->id);
        $this->assertSame(42, $eventsRead[0]->data['value']);
    }
}
