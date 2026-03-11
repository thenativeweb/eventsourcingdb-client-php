<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class Bound implements JsonSerializable
{
    public function __construct(
        public string $id,
        public BoundType $type,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
        ];
    }
}
