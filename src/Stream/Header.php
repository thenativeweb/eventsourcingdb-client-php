<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

final readonly class Header
{
    public function __construct(
        public int $statusCode,
        public string $httpVersion,
        public string $contentType,
        public int $contentLength,
    ) {
    }
}
