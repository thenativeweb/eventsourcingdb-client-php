<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

final readonly class ReadSubjectsOptions
{
    /**
     * @param bool $recursive whether to list the subjects below the given subject as well, rather than the given subject alone
     */
    public function __construct(
        public bool $recursive,
    ) {
    }
}
