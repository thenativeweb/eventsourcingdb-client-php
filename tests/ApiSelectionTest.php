<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Thenativeweb\Eventsourcingdb\Api\V1Transport;
use Thenativeweb\Eventsourcingdb\Api\V2Transport;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

/**
 * Proves which API the client speaks with the database of the image that the
 * tests run against, so that a client that fell back to the v1 API, which
 * EventSourcingDB 1.3 still offers, does not go unnoticed.
 */
final class ApiSelectionTest extends TestCase
{
    use ClientTestTrait;

    public function testUsesTheApiThatTheDatabaseOffers(): void
    {
        $offersV2Api = $this->askWhetherTheDatabaseOffersTheV2Api();

        $this->client->ping();

        $transportSelector = (new ReflectionProperty($this->client, 'transportSelector'))->getValue($this->client);
        $transport = (new ReflectionProperty($transportSelector, 'transport'))->getValue($transportSelector);

        $this->assertInstanceOf($offersV2Api ? V2Transport::class : V1Transport::class, $transport);
    }
}
