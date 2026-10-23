<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsSubjectOnEventId implements JsonSerializable
{
    public function __construct(
        public string $subject,
        public string $eventId,
        public ?PreconditionOptions $options = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $payload = [
            'subject' => $this->subject,
            'eventId' => $this->eventId,
        ];

        if ($this->options instanceof PreconditionOptions) {
            $payload['options'] = $this->options->jsonSerialize();
        }

        return [
            'type' => 'isSubjectOnEventId',
            'payload' => $payload,
        ];
    }
}
