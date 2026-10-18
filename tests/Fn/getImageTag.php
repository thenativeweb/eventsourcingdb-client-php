<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Fn;

use RuntimeException;

/**
 * Returns the tag of the EventSourcingDB image to test against. The
 * environment variable EVENTSOURCINGDB_IMAGE_TAG decides it, so that the same
 * tests run against several tags; without it, the tag of the Dockerfile is
 * used.
 */
function getImageTag(): string
{
    $imageTag = getenv('EVENTSOURCINGDB_IMAGE_TAG');
    if ($imageTag !== false && trim($imageTag) !== '') {
        return trim($imageTag);
    }

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
