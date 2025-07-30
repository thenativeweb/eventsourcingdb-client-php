<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use IteratorAggregate;
use RuntimeException;
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

    public function getJsonData(): array
    {
        $contents = $this->getContents();
        if ($contents === '') {
            return [];
        }

        if (!json_validate($contents)) {
            throw new RuntimeException('invalid json string');
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            $dataType = gettype($data);
            throw new RuntimeException("json data is from type '{$dataType}', expected an array");
        }

        return $data;
    }
}
