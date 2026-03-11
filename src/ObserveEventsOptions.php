<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

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
