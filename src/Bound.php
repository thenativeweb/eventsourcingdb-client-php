<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

enum BoundType: string
{
    case INCLUSIVE = 'inclusive';
    case EXCLUSIVE = 'exclusive';
}

class Bound implements JsonSerializable
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
