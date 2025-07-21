<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
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

        $this->client->abortStream(0.2);
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

        $this->client->abortStream(0.2);
        foreach ($this->client->observeEvents('/test', $observeEventsOptions) as $event) {
            $eventsObserved[] = $event;
        }

        $this->assertCount(2, $eventsObserved);
    }
}
