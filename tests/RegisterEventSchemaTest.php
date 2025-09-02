<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to register event schema, got HTTP status code '409', expected '200'");

        $this->client->registerEventSchema($eventType, $schema);
    }
}
