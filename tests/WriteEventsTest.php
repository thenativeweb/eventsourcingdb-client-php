<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\IncompatibleServerException;
use Thenativeweb\Eventsourcingdb\IsEventQlQueryTrue;
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
use Thenativeweb\Eventsourcingdb\PreconditionOptions;
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

    public function testReturnsDataWithLongRunsOfWhitespaceCompletely(): void
    {
        // cURL hands over a large response in chunks, some of which consist
        // of nothing but the spaces in the middle of the value.
        $value = str_repeat(' ', 40_000) . 'x' . str_repeat(' ', 20_000);

        $writtenEvents = $this->client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => $value,
                ],
            ),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame($value, $writtenEvents[0]->data['value']);

        $writtenEvents[0]->verifyHash();
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

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectPristine('/test'),
            ],
        ), 'Failed to write events, ');
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

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents(
            [
                $eventCandidate,
            ],
            [
                new IsSubjectPopulated('/test'),
            ],
        ), 'Failed to write events, ');
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

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsSubjectOnEventId('/test', '1'),
            ],
        ), 'Failed to write events, ');
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

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents(
            [
                $secondEvent,
            ],
            [
                new IsEventQlQueryTrue('FROM e IN events PROJECT INTO COUNT() == 0'),
            ],
        ), 'Failed to write events, ');
    }

    public function testSupportsTheIsSubjectPristinePreconditionWithOptionsThatAreNotRecursive(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditions = [
            new IsSubjectPristine('/test', new PreconditionOptions(recursive: false)),
        ];

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], $preconditions);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('/test', $writtenEvents[0]->subject);

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], $preconditions), 'Failed to write events, ');
    }

    public function testSupportsTheIsSubjectPristinePreconditionRecursively(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditionOptions = new PreconditionOptions(recursive: true);

        if (!$this->usesV2Api($this->client)) {
            $this->assertFailsForLackOfTheV2Api(new IsSubjectPristine('/test/2', $preconditionOptions));

            return;
        }

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectPristine('/test', $preconditionOptions),
        ]), 'Failed to write events, ');

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test/2'),
        ], [
            new IsSubjectPristine('/test/2', $preconditionOptions),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('/test/2', $writtenEvents[0]->subject);
    }

    public function testSupportsTheIsSubjectPopulatedPreconditionWithOptionsThatAreNotRecursive(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditionOptions = new PreconditionOptions(recursive: false);

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectPopulated('/test', $preconditionOptions),
        ]), 'Failed to write events, ');

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test/1'),
        ], [
            new IsSubjectPopulated('/test/1', $preconditionOptions),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('/test/1', $writtenEvents[0]->subject);
    }

    public function testSupportsTheIsSubjectPopulatedPreconditionRecursively(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditionOptions = new PreconditionOptions(recursive: true);

        if (!$this->usesV2Api($this->client)) {
            $this->assertFailsForLackOfTheV2Api(new IsSubjectPopulated('/test/1', $preconditionOptions));

            return;
        }

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test/2'),
        ], [
            new IsSubjectPopulated('/test/2', $preconditionOptions),
        ]), 'Failed to write events, ');

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectPopulated('/test', $preconditionOptions),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('/test', $writtenEvents[0]->subject);
    }

    public function testSupportsTheIsSubjectOnEventIdPreconditionWithOptionsThatAreNotRecursive(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test'),
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditionOptions = new PreconditionOptions(recursive: false);

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectOnEventId('/test', '1', $preconditionOptions),
        ]), 'Failed to write events, ');

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectOnEventId('/test', '0', $preconditionOptions),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('2', $writtenEvents[0]->id);
    }

    public function testSupportsTheIsSubjectOnEventIdPreconditionRecursively(): void
    {
        $this->client->writeEvents([
            $this->createEventCandidate('/test'),
            $this->createEventCandidate('/test/1'),
        ]);

        $preconditionOptions = new PreconditionOptions(recursive: true);

        if (!$this->usesV2Api($this->client)) {
            $this->assertFailsForLackOfTheV2Api(new IsSubjectOnEventId('/test', '0', $preconditionOptions));

            return;
        }

        $this->assertFailsWithErrorCode(ErrorCode::PRECONDITION_VIOLATED, fn (): mixed => $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectOnEventId('/test', '0', $preconditionOptions),
        ]), 'Failed to write events, ');

        $writtenEvents = $this->client->writeEvents([
            $this->createEventCandidate('/test'),
        ], [
            new IsSubjectOnEventId('/test', '1', $preconditionOptions),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('2', $writtenEvents[0]->id);
    }

    /**
     * Asserts that a write with the given recursive precondition fails, since
     * the server does not offer the v2 API, and that it writes nothing. Each
     * caller passes a precondition that holds when checked without its
     * options, so that a write that ignored them would succeed.
     */
    private function assertFailsForLackOfTheV2Api(IsSubjectPristine|IsSubjectPopulated|IsSubjectOnEventId $precondition): void
    {
        $eventsBefore = $this->readAllEventIds();

        try {
            $this->client->writeEvents([
                $this->createEventCandidate('/test/2'),
            ], [
                $precondition,
            ]);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (IncompatibleServerException $incompatibleServerException) {
            $this->assertSame('The server does not offer the v2 API, which a recursive precondition needs.', $incompatibleServerException->getMessage());
        }

        $this->assertSame($eventsBefore, $this->readAllEventIds(), 'Expected the write to write nothing.');
    }

    /**
     * @return list<string>
     */
    private function readAllEventIds(): array
    {
        $eventIds = [];
        foreach ($this->client->readEvents('/', new ReadEventsOptions(recursive: true)) as $event) {
            $eventIds[] = $event->id;
        }

        return $eventIds;
    }

    private function createEventCandidate(string $subject): EventCandidate
    {
        return new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: $subject,
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );
    }
}
