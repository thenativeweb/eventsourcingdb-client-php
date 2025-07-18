<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

enum Order: string
{
    case CHRONOLOGICAL = 'chronological';
    case ANTICHRONOLOGICAL = 'antichronological';
}

enum ReadIfEventIsMissing: string
{
    case READ_NOTHING = 'read-nothing';
    case READ_EVERYTHING = 'read-everything';
}

class ReadFromLatestEvent implements JsonSerializable
{
    public function __construct(
        public string $subject,
        public string $type,
        public ReadIfEventIsMissing $ifEventIsMissing,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'subject' => $this->subject,
            'type' => $this->type,
            'ifEventIsMissing' => $this->ifEventIsMissing->value,
        ];
    }
}

class ReadEventsOptions implements JsonSerializable
{
    public function __construct(
        public bool $recursive = false,
        public ?Order $order = null,
        public ?Bound $lowerBound = null,
        public ?Bound $upperBound = null,
        public ?ReadFromLatestEvent $fromLatestEvent = null
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'recursive' => $this->recursive,
            'order' => $this->order?->value,
            'lowerBound' => $this->lowerBound?->jsonSerialize(),
            'upperBound' => $this->upperBound?->jsonSerialize(),
            'fromLatestEvent' => $this->fromLatestEvent?->jsonSerialize(),
        ], fn ($v): bool => $v !== null);
    }
}
