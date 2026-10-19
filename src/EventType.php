<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class EventType implements JsonSerializable
{
    /**
     * @param string $type the name of the event type
     * @param bool $hasEvents whether events of the type have been written, rather than only a schema registered for it
     * @param array $schema the registered schema of the event type, or an empty array if none is registered
     */
    public function __construct(
        public string $type,
        public bool $hasEvents,
        public array $schema = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'hasEvents' => $this->hasEvents,
            'schema' => $this->schema,
        ];
    }
}
