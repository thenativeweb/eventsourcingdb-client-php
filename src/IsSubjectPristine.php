<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsSubjectPristine implements JsonSerializable
{
    public function __construct(
        public string $subject,
        public ?PreconditionOptions $options = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $payload = [
            'subject' => $this->subject,
        ];

        if ($this->options instanceof PreconditionOptions) {
            $payload['options'] = $this->options->jsonSerialize();
        }

        return [
            'type' => 'isSubjectPristine',
            'payload' => $payload,
        ];
    }
}
