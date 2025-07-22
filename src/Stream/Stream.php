<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use IteratorAggregate;
use Stringable;
use Traversable;

class Stream implements IteratorAggregate, Stringable
{
    public function __construct(
        private CurlMultiHandler $curlMultiHandler
    ) {
    }

    public function getIterator(): Traversable
    {
        foreach ($this->curlMultiHandler->contentIterator() as $chunk) {
            yield $chunk;
        }
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getContents(): string
    {
        $content = '';
        foreach ($this->getIterator() as $chunk) {
            $content .= $chunk;
        }

        return $content;
    }

    public function cancel(float $timeout = 0.0): void
    {
        $this->curlMultiHandler->setStreamTimeout($timeout);
    }
}
