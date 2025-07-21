<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class ResponseHeader
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $httpVersion,
    ) {
    }
}
