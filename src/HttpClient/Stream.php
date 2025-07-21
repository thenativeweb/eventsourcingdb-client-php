<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class Stream implements StreamInterface
{
    public function __construct(
        private readonly CurlMultiHandler $curlMultiHandler
    ) {
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->curlMultiHandler->startIterator() as $chunk) {
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

    public function abortTimeout(float $timeout = 0.0): void
    {
        $this->curlMultiHandler->setStreamTimeout($timeout);
    }
}
