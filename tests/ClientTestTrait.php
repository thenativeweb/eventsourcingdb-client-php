<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Thenativeweb\Eventsourcingdb\Container;

trait ClientTestTrait
{
    protected function startContainer(): Container
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())->withImageTag($imageVersion);
        $container->start();

        return $container;
    }
}
