<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use IteratorAggregate;
use RuntimeException;
use Stringable;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;
use Traversable;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\StreamTest
 */
readonly class Stream implements IteratorAggregate, Stringable
{
    public function __construct(
        private CurlMultiHandler $curlMultiHandler,
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

    public function close(): void
    {
        $this->curlMultiHandler->close();
    }

    /**
     * Reads the whole answer. If abortIn ends reading it, it throws a
     * RequestAbortedException, rather than hand over a part of the answer as
     * though it were all of it.
     */
    public function getContents(): string
    {
        $contents = implode('', iterator_to_array($this));

        if ($this->curlMultiHandler->hasLastStreamBeenAborted()) {
            throw new RequestAbortedException();
        }

        return $contents;
    }

    public function getJsonData(): array
    {
        return self::decodeJson($this->getContents());
    }

    public static function decodeJson(string $contents): array
    {
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
