<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

final readonly class ReadEventLine
{
    /**
     * For the property payload, we agreed on mixed, as a wide variety of types can be transmitted.
     * Complex data types such as objects or resources are excluded.
     */
    public function __construct(
        public string $type,
        public mixed $payload,
    ) {
    }
}
