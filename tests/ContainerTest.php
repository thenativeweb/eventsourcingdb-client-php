<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Container;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageVersionFromDockerfile;

final class ContainerTest extends TestCase
{
    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container?->stop();
        parent::tearDown();
    }

    public function testStartsWithACustomPort(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $this->container = (new Container())
            ->withImageTag($imageVersion)
            ->withPort(4000);
        $this->container->start();

        $client = $this->container->getClient();

        $client->ping();
        $this->expectNotToPerformAssertions();
    }
}
