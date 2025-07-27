<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class ReadEventTypeTest extends TestCase
{
    use ClientTestTrait;

    public function testFailsIfTheEventTypeDoesNotExist(): void
    {
        $this->expectExceptionMessage("Failed to read event type, got HTTP status code '404', expected '200'");

        $this->client->readEventType('non.existent.eventType');
    }

    public function testFailsIfTheEventTypeIsMalformed(): void
    {
        $this->expectExceptionMessage("Failed to read event type, got HTTP status code '400', expected '200'");

        $this->client->readEventType('malformed.eventType.');
    }

    public function testReadAnExistingEventType(): void
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

        $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $eventType = $this->client->readEventType('io.eventsourcingdb.test.foo');

        $expected = new EventType(
            eventType: 'io.eventsourcingdb.test.foo',
            isPhantom: false,
            schema: [],
        );

        $this->assertEquals($expected, $eventType);
    }
}
