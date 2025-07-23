<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class IsEventQlTrue implements JsonSerializable
{
    public function __construct(
        public string $query,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'isEventQlTrue',
            'payload' => [
                'query' => $this->query,
            ],
        ];
    }
}
