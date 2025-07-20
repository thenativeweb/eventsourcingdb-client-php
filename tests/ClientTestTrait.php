<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Container;

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
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())->withImageTag($imageVersion);
        $container->start();

        return $container;
    }
}
