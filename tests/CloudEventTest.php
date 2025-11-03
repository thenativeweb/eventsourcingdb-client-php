<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
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
            ]
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

    public function testThrowsAnErrorIfTheEventHashIsInvalid(): void
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ]
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

        $this->expectException(RuntimeException::class);
        $tamperedCloudEvent->verifyHash();
    }
}
