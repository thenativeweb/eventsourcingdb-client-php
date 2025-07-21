<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class BufferQueue implements \IteratorAggregate
{
    public function __construct(
        private array $bufferQueue = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->bufferQueue === [];
    }

    public function read(): string
    {
        if ($this->bufferQueue === []) {
            return '';
        }

        return array_shift($this->bufferQueue);
    }

    public function write(string $data): void
    {
        $this->bufferQueue[] = $data;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->bufferQueue);
    }
}
