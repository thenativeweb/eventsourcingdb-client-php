<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

enum ObserveIfEventIsMissing: string
{
    case READ_EVERYTHING = 'read-everything';
    case WAIT_FOR_EVENT = 'wait-for-event';
}

final readonly class ObserveFromLatestEvent implements JsonSerializable
{
    public function __construct(
        public string $subject,
        public string $type,
        public ObserveIfEventIsMissing $ifEventIsMissing,
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

final readonly class ObserveEventsOptions implements JsonSerializable
{
    public function __construct(
        public bool $recursive,
        public ?Bound $lowerBound = null,
        public ?ObserveFromLatestEvent $fromLatestEvent = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $return = [
            'recursive' => $this->recursive,
        ];

        if ($this->lowerBound instanceof Bound) {
            $return['lowerBound'] = $this->lowerBound->jsonSerialize();
        }

        if ($this->fromLatestEvent instanceof ObserveFromLatestEvent) {
            $return['fromLatestEvent'] = $this->fromLatestEvent->jsonSerialize();
        }

        return $return;
    }
}
