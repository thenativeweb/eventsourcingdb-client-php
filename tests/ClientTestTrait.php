<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Thenativeweb\Eventsourcingdb\Container;
use function Thenativeweb\Eventsourcingdb\Tests\getImageVersionFromDockerfile;

trait ClientTestTrait
{
    protected function bootContainer(): Container
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())->withImageTag($imageVersion);
        $container->start();

        return $container;
    }
}
