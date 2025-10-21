<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsSubjectPopulated implements JsonSerializable
{
    public function __construct(
        public string $subject,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'isSubjectPopulated',
            'payload' => [
                'subject' => $this->subject,
            ],
        ];
    }
}
