<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsSubjectOnEventId implements JsonSerializable
{
    public function __construct(
        public string $subject,
        public string $eventId,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'isSubjectOnEventId',
            'payload' => [
                'subject' => $this->subject,
                'eventId' => $this->eventId,
            ],
        ];
    }
}
