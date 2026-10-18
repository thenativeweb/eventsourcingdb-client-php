<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Trait;

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

    protected function startContainer(): Container
    {
        $imageTag = getImageTag();
        $container = (new Container())->withImageTag($imageTag);
        $container->start();

        return $container;
    }
}
