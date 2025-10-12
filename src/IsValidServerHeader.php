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
    return str_starts_with($serverHeader[0], 'EventSourcingDB/');
}
