<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\HashVerificationFailedException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;

final class CloudEventTest extends TestCase
{
    use ClientTestTrait;
    use ReflectionTestTrait;

    public function testVerifiesTheEventHash(): void
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $this->client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        try {
            $writtenEvent->verifyHash();
        } catch (RuntimeException $runtimeException) {
            $this->fail($runtimeException->getMessage());
        }
    }

    public function testVerifiesTheHashOfEventsAsTheyAreWrittenReadAndObserved(): void
    {
        // The data holds characters and numbers that PHP writes differently
        // than the server does, so the hash only matches if the data is
        // hashed as it was received.
        $eventCandidates = [
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/books/42',
                type: 'io.eventsourcingdb.library.book-acquired',
                data: [
                    'title' => '2001 – A Space Odyssey',
                    'url' => 'https://www.eventsourcingdb.io/books/42',
                    'shelf' => 'A&B <classic>',
                    'price' => 12.5,
                    'ratio' => 1.0,
                    'copies' => 3,
                    'tags' => ['sf', 'classic'],
                    'empty' => [],
                ],
            ),
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/books/42',
                type: 'io.eventsourcingdb.library.book-borrowed',
                data: [
                    'borrower' => 'Jane Doe',
                ],
                traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
                traceState: 'rojo=00f067aa0ba902b7',
            ),
        ];

        $writtenEvents = $this->client->writeEvents($eventCandidates);

        $readEvents = iterator_to_array($this->client->readEvents('/books/42', new ReadEventsOptions(recursive: false)), false);

        $observedEvents = [];
        foreach ($this->client->observeEvents('/books/42', new ObserveEventsOptions(recursive: false)) as $event) {
            $observedEvents[] = $event;
            if (count($observedEvents) === count($eventCandidates)) {
                break;
            }
        }

        $this->assertCount(2, $writtenEvents);
        $this->assertCount(2, $readEvents);
        $this->assertCount(2, $observedEvents);

        foreach ([...$writtenEvents, ...$readEvents, ...$observedEvents] as $event) {
            $this->assertInstanceOf(CloudEvent::class, $event);

            try {
                $event->verifyHash();
            } catch (RuntimeException $runtimeException) {
                $this->fail("Failed to verify the hash '{$event->hash}' of event {$event->id}: {$runtimeException->getMessage()}");
            }
        }
    }

    public function testThrowsAnErrorIfTheEventHashIsInvalid(): void
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $this->client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        $tamperedCloudEvent = new CloudEvent(
            specVersion: $writtenEvent->specVersion,
            id: $writtenEvent->id,
            time: $writtenEvent->time,
            timeFromServer: $this->getPropertyValue($writtenEvent, 'timeFromServer'),
            source: $writtenEvent->source,
            subject: $writtenEvent->subject,
            type: $writtenEvent->type,
            dataContentType: $writtenEvent->dataContentType,
            data: $writtenEvent->data,
            hash: hash('sha256', 'invalid hash'),
            predecessorHash: $writtenEvent->predecessorHash,
            traceParent: $writtenEvent->traceParent,
            traceState: $writtenEvent->traceState,
            signature: $writtenEvent->signature,
        );

        $this->expectException(HashVerificationFailedException::class);
        $this->expectExceptionMessage('Failed to verify hash.');

        $tamperedCloudEvent->verifyHash();
    }
}
