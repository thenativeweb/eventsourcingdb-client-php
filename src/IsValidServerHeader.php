<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Thenativeweb\Eventsourcingdb\Stream\Response;

function isValidServerHeader(Response $response): bool
{
    $serverHeader = $response->getHeader('Server');

    if ($serverHeader === []) {
        return false;
    }

    if (!str_starts_with($serverHeader[0], 'EventSourcingDB/')) {
        return false;
    }

    return true;
}
