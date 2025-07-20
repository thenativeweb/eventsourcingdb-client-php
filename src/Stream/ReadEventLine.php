<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

final readonly class ReadEventLine
{
    public function __construct(
        public string $type,
        public array $payload,
    ) {
    }
}
