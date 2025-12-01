<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class PingTest extends TestCase
{
    use ClientTestTrait;

    public function testSucceedsWhenServerIsReachable(): void
    {
        $this->client->ping();
        $this->expectNotToPerformAssertions();
    }

    public function testFailsWhenServerIsUnreachable(): void
    {
        $port = $this->container->getMappedPort();
        $client = new Client("http://non-existent-host:{$port}", $this->container->getApiToken());

        $this->expectException(\Throwable::class);
        $client->ping();
    }
}
