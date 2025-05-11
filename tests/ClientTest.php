<?php

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Container;

class ClientTest extends TestCase
{
    public function testPingSucceedsWhenServerIsReachable(): void
    {
        $container = new Container();
        $container->start();
        try {
            $client = $container->getClient();
            $client->ping();
            $this->expectNotToPerformAssertions();
        } finally {
            $container->stop();
        }
    }

    public function testPingFailsWhenServerIsUnreachable(): void
    {
        $container = new Container();
        $container->start();
        try {
            $port = $container->getMappedPort();
            $client = new Client("http://non-existent-host:{$port}", $container->getApiToken());

            $this->expectException(Throwable::class);
            $client->ping();
        } finally {
            $container->stop();
        }
    }
}
