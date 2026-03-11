<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

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
