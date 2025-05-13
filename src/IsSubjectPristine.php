<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsSubjectPristine implements JsonSerializable
{
    public function __construct(
        public string $subject,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'isSubjectPristine',
            'payload' => [
                'subject' => $this->subject,
            ],
        ];
    }
}
