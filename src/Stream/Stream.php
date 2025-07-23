<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use IteratorAggregate;
use Stringable;
use Traversable;

readonly class Stream implements IteratorAggregate, Stringable
{
    public function __construct(
        private CurlMultiHandler $curlMultiHandler
    ) {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getIterator(): Traversable
    {
        foreach ($this->curlMultiHandler->contentIterator() as $chunk) {
            yield $chunk;
        }
    }

    public function getContents(): string
    {
        return implode('', iterator_to_array($this));
    }
}
