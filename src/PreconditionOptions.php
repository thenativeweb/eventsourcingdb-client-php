<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class PreconditionOptions implements JsonSerializable
{
    /**
     * @param bool $recursive whether the precondition applies to the subjects below the given subject as well, rather than to the given subject alone, which only the v2 API offers
     */
    public function __construct(
        public bool $recursive = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'recursive' => $this->recursive,
        ];
    }
}
