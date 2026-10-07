<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\IsEventQlQueryTrue;
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

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

    public function testWritesTheTraceContextOfAnEvent(): void
    {
        $traceParent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $traceState = 'rojo=00f067aa0ba902b7';

        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
            traceParent: $traceParent,
            traceState: $traceState,
        );

        $writtenEvents = $this->client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame($traceParent, $writtenEvents[0]->traceParent);
        $this->assertSame($traceState, $writtenEvents[0]->traceState);

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(false);

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(1, $eventsRead);
        $this->assertSame($traceParent, $eventsRead[0]->traceParent);
        $this->assertSame($traceState, $eventsRead[0]->traceState);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write events, state conflict: precondition failed');
        $this->expectExceptionCode(409);

        $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectPristine('/test'),
            ],
        );
    }

    public function testRejectsWritingToEmptySubjectWhenUsingTheIsSubjectPopulatedPrecondition(): void
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write events, state conflict: precondition failed');
        $this->expectExceptionCode(409);

        $this->client->writeEvents(
            [
                $eventCandidate,
            ],
            [
                new IsSubjectPopulated('/test'),
            ],
        );
    }

    public function testSupportsTheIsSubjectPopulatedPrecondition(): void
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

        $this->client->writeEvents([
            $firstEvent,
        ]);

        $writtenEvents = $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectPopulated('/test'),
            ],
        );

        $this->assertCount(1, $writtenEvents);
        $this->assertInstanceOf(CloudEvent::class, $writtenEvents[0]);
        $this->assertSame('1', $writtenEvents[0]->id);
        $this->assertSame(42, $writtenEvents[0]->data['value']);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write events, state conflict: precondition failed');
        $this->expectExceptionCode(409);

        $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectOnEventId('/test', '1'),
            ],
        );
    }

    public function testSupportsTheIsEventQlQueryTruePrecondition(): void
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write events, state conflict: precondition failed');
        $this->expectExceptionCode(409);

        $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsEventQlQueryTrue('FROM e IN events PROJECT INTO COUNT() == 0'),
            ],
        );
    }
}
