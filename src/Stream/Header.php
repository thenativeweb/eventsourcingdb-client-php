<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

class Header
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $httpVersion,
        public readonly string $contentType,
        public readonly int $contentLength,
    ) {
    }
}
