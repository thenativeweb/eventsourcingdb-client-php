<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class RegisterEventSchemaTest extends TestCase
{
    use ClientTestTrait;

    public function testRegisterAnEventSchema(): void
    {
        $eventType = 'io.eventsourcingdb.test';
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

        $this->client->registerEventSchema($eventType, $schema);

        $this->assertFalse($this->hasUnexpectedOutput());
    }

    public function testThrowsAnErrorIfAnEventSchemaIsAlreadyRegistered(): void
    {
        $eventType = 'io.eventsourcingdb.test';
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

        $this->client->registerEventSchema($eventType, $schema);

        $this->assertFailsWithErrorCode(ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED, fn (): mixed => $this->client->registerEventSchema($eventType, $schema));
    }
}
