<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

class EventType implements JsonSerializable
{
    public function __construct(
        public string $eventType,
        public bool $isPhantom,
        public array $schema = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'eventType' => $this->eventType,
            'isPhantom' => $this->isPhantom,
            'schema' => $this->schema,
        ];
    }
}
