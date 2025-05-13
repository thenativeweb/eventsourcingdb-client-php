<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;

final readonly class CloudEvent
{
    public function __construct(
        public string $specVersion,
        public string $id,
        public DateTimeImmutable $time,
        public string $source,
        public string $subject,
        public string $type,
        public string $dataContentType,
        public array $data,
        public string $hash,
        public string $predecessorHash,
        public ?string $traceParent = null,
        public ?string $traceState = null,
    ) {
    }
}
