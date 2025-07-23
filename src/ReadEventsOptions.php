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

final readonly class ReadFromLatestEvent implements JsonSerializable
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

final readonly class ReadEventsOptions implements JsonSerializable
{
    public function __construct(
        public bool $recursive = false,
        public ?Order $order = null,
        public ?Bound $lowerBound = null,
        public ?Bound $upperBound = null,
        public ?ReadFromLatestEvent $fromLatestEvent = null
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = [
            'recursive' => $this->recursive,
        ];

        if ($this->order instanceof Order) {
            $result['order'] = $this->order->value;
        }

        if ($this->lowerBound instanceof Bound) {
            $result['lowerBound'] = $this->lowerBound->jsonSerialize();
        }

        if ($this->upperBound instanceof Bound) {
            $result['upperBound'] = $this->upperBound->jsonSerialize();
        }

        if ($this->fromLatestEvent instanceof ReadFromLatestEvent) {
            $result['fromLatestEvent'] = $this->fromLatestEvent->jsonSerialize();
        }

        return $result;
    }
}
