<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\IsEventQlTrue;
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class WriteEventsTest extends TestCase
{
    use ClientTestTrait;

    public function testWritesASingleEvent(): void
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $writtenEvents = $this->client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertInstanceOf(CloudEvent::class, $writtenEvents[0]);
        $this->assertSame('0', $writtenEvents[0]->id);
    }

    public function testWritesMultipleEvents(): void
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

        $writtenEvents = $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $this->assertCount(2, $writtenEvents);
        $this->assertInstanceOf(CloudEvent::class, $writtenEvents[0]);
        $this->assertSame('0', $writtenEvents[0]->id);
        $this->assertSame(23, $writtenEvents[0]->data['value']);
        $this->assertInstanceOf(CloudEvent::class, $writtenEvents[1]);
        $this->assertSame('1', $writtenEvents[1]->id);
        $this->assertSame(42, $writtenEvents[1]->data['value']);
    }

    public function testSupportsTheIsSubjectPristinePrecondition(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
        ]);

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->expectExceptionMessage("Failed to write events, got HTTP status code '409', expected '200'");

        iterator_to_array($this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectPristine('/test'),
            ],
        ));
    }

    public function testSupportsTheIsSubjectOnEventIdPrecondition(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
        ]);

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->expectExceptionMessage("Failed to write events, got HTTP status code '409', expected '200'");
        iterator_to_array($this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectOnEventId('/test', '1'),
            ],
        ));
    }

    public function testSupportsTheIsEventQlTruePrecondition(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
        ]);

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->expectExceptionMessage("Failed to write events, got HTTP status code '409', expected '200'");
        iterator_to_array($this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsEventQlTrue('FROM e IN events PROJECT INTO COUNT() == 0'),
            ],
        ));
    }
}
