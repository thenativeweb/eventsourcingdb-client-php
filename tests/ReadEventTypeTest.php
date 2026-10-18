<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class ReadEventTypeTest extends TestCase
{
    use ClientTestTrait;

    public function testFailsIfTheEventTypeDoesNotExist(): void
    {
        $this->expectException(DbApiException::class);
        $this->expectExceptionMessage('Failed to read event type, ');
        $this->expectExceptionCode(ErrorCode::EVENT_TYPE_UNKNOWN);

        $this->client->readEventType('non.existent.eventType');
    }

    public function testFailsIfTheEventTypeIsMalformed(): void
    {
        // The v1 API refuses a malformed event type as a bad request, without
        // a code.
        $usesV2Api = $this->usesV2Api($this->client);

        try {
            $this->client->readEventType('malformed.eventType.');
            $this->fail('Expected reading the event type to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertStringStartsWith('Failed to read event type, ', $dbApiException->getMessage());
            $this->assertSame($usesV2Api ? ErrorCode::EVENT_TYPE_MALFORMED : '', $dbApiException->code);
            $this->assertSame($usesV2Api ? 200 : 400, $dbApiException->status);
        }
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
            type: 'io.eventsourcingdb.test.foo',
            hasEvents: true,
            schema: [],
        );

        $this->assertEquals($expected, $eventType);
    }

    public function testReadsAnEventTypeThatHasASchemaButNoEvents(): void
    {
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

        $this->client->registerEventSchema('io.eventsourcingdb.test', $schema);

        $eventType = $this->client->readEventType('io.eventsourcingdb.test');

        $this->assertSame('io.eventsourcingdb.test', $eventType->type);
        $this->assertFalse($eventType->hasEvents);
        $this->assertEquals($schema, $eventType->schema);
    }
}
