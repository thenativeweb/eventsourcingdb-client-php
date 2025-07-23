<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Bound;
use Thenativeweb\Eventsourcingdb\BoundType;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ObserveFromLatestEvent;
use Thenativeweb\Eventsourcingdb\ObserveIfEventIsMissing;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class ObserveEventsTest extends TestCase
{
    use ClientTestTrait;

    public function testObservesNoEventsIfTheDatabaseIsEmpty(): void
    {
        $didObserveEvents = false;
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: true,
        );

        $this->client->abortIn(0.1);
        foreach ($this->client->observeEvents('/', $observeEventsOptions) as $event) {
            $didObserveEvents = true;
        }

        $this->assertFalse($didObserveEvents, 'Expected no events to be read, but some were found.');
    }

    public function testObserverAllEventsFromTheGivenSubject(): void
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

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: false,
        );

        $this->client->abortIn(0.1);
        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $this->assertCount(2, $eventsObserved);
    }

    public function testObserversRecursively(): void
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

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: true,
        );

        $this->client->abortIn(0.1);
        foreach ($this->client->observeEvents('/', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $this->assertCount(2, $eventsObserved);
    }

    public function testObserversWithLowerBound(): void
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

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: false,
            lowerBound: new Bound(
                id: '1',
                type: BoundType::INCLUSIVE,
            ),
        );

        $this->client->abortIn(0.1);
        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $this->assertCount(1, $eventsObserved);
        $this->assertInstanceOf(CloudEvent::class, $eventsObserved[0]);
        $this->assertSame('1', $eventsObserved[0]->id);
        $this->assertSame(42, $eventsObserved[0]->data['value']);
    }

    public function testObserversFromLatestEvent(): void
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

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: false,
            fromLatestEvent: new ObserveFromLatestEvent(
                subject: '/test',
                type: 'io.eventsourcingdb.test.bar',
                ifEventIsMissing: ObserveIfEventIsMissing::READ_EVERYTHING,
            ),
        );

        $this->client->abortIn(0.1);
        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $this->assertCount(1, $eventsObserved);
        $this->assertInstanceOf(CloudEvent::class, $eventsObserved[0]);
        $this->assertSame('1', $eventsObserved[0]->id);
        $this->assertSame(42, $eventsObserved[0]->data['value']);
    }

    public function testObserverAllEventsWithAbortInLoop(): void
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

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: false,
        );

        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;

            $this->client->abortIn(0.5);
        }

        $this->assertCount(2, $eventsObserved);
    }

    public function testObserverAllEventsPerformanceBenchmark(): void
    {
        $eventCount = 100;
        $events = [];

        for ($i = 0; $i < $eventCount; ++$i) {
            $events[] = new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => rand(1000, 9999),
                ],
            );
        }

        $count = iterator_count($this->client->writeEvents($events));
        $this->assertSame($eventCount, $count);

        $eventsObserved = [];
        $observeEventsOptions = new ObserveEventsOptions(
            recursive: false,
        );

        $startTime = microtime(true);
        $maxExecutionTime = 2.0;

        $this->client->abortIn(0.5);
        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $processTime = microtime(true) - $startTime;

        $this->assertCount($eventCount, $eventsObserved);
        $this->assertLessThan($maxExecutionTime, $processTime, "Expected to observe all events in less than {$maxExecutionTime} second, but took {$processTime} seconds.");
    }
}
