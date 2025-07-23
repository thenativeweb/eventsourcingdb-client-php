<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Fn;

use RuntimeException;

function getImageVersionFromDockerfile(): string
{
    $dockerfile = __DIR__ . '/../../docker/Dockerfile';

    if (!file_exists($dockerfile)) {
        throw new RuntimeException('Dockerfile not found at ' . $dockerfile);
    }

    $content = file_get_contents($dockerfile);
    if (!preg_match('/^FROM\s+thenativeweb\/eventsourcingdb:(.+)$/m', $content, $matches)) {
        throw new RuntimeException('Failed to extract image version from Dockerfile');
    }

    return trim($matches[1]);
}
