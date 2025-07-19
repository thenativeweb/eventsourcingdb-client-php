<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use JsonSerializable;

final readonly class EventCandidate implements JsonSerializable
{
    public function __construct(
        public string $source,
        public string $subject,
        public string $type,
        public array $data,
        public ?string $traceParent = null,
        public ?string $traceState = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = [
            'source' => $this->source,
            'subject' => $this->subject,
            'type' => $this->type,
            'data' => $this->data,
        ];

        if ($this->traceParent !== null) {
            $result['traceParent'] = $this->traceParent;
        }

        if ($this->traceState !== null) {
            $result['traceState'] = $this->traceState;
        }

        return $result;
    }
}
