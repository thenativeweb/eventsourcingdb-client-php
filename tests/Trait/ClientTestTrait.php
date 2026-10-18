<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Trait;

use ReflectionProperty;
use Thenativeweb\Eventsourcingdb\Api\V2Transport;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Container;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageTag;

trait ClientTestTrait
{
    private Container $container;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->startContainer();
        $this->client = $this->container->getClient();
    }

    protected function tearDown(): void
    {
        $this->container->stop();
        parent::tearDown();
    }

    /**
     * Tells whether the client speaks the v2 API with the database, which it
     * knows once it has sent its first request.
     */
    protected function usesV2Api(Client $client): bool
    {
        $client->ping();

        $transportSelector = (new ReflectionProperty($client, 'transportSelector'))->getValue($client);
        $transport = (new ReflectionProperty($transportSelector, 'transport'))->getValue($transportSelector);

        return $transport instanceof V2Transport;
    }

    protected function startContainer(): Container
    {
        $imageTag = getImageTag();
        $container = (new Container())->withImageTag($imageTag);
        $container->start();

        return $container;
    }
}
