<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

/**
 * @internal
 */
final readonly class ReadEventLine
{
    /**
     * For the property payload, we agreed on mixed, as a wide variety of types can be transmitted.
     * Complex data types such as objects or resources are excluded.
     *
     * The line is kept as it arrived, so that values can be taken from it
     * byte for byte, such as the data of an event to verify its hash.
     */
    public function __construct(
        public string $type,
        public mixed $payload,
        public string $line = '',
    ) {
    }
}
