<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

class Queue implements IteratorAggregate
{
    public function __construct(
        private array $queue = [],
        private readonly int $maxSize = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    public function read(): string
    {
        if ($this->queue === []) {
            return '';
        }

        return array_shift($this->queue);
    }

    public function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->queue[] = $data;

        if ($this->maxSize > 0 && count($this->queue) > $this->maxSize) {
            $this->queue = array_slice($this->queue, -$this->maxSize);
        }
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->queue);
    }
}
