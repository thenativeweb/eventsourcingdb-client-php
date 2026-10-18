<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use RuntimeException;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\NdJsonTest
 */
final readonly class NdJson
{
    /**
     * Reads the items of a stream. Each item names its type, and carries its
     * data in the member with the given name, which is payload in the v1 API
     * of EventSourcingDB, and data in the v2 API.
     */
    public static function readStream(Stream $stream, string $dataKey = 'payload'): iterable
    {
        foreach ($stream as $chunk) {
            // A chunk ends with a line break, but may hold several lines, e.g.
            // if a proxy joins them.
            foreach (explode("\n", $chunk) as $line) {
                if (trim($line) === '') {
                    continue;
                }

                if (!json_validate($line)) {
                    throw new RuntimeException('Failed to read events, when processing the ndjson.');
                }

                $item = json_decode($line, true);
                if (!is_array($item)) {
                    throw new RuntimeException('Failed to read events, expected an array.');
                }

                $eventLine = new ReadEventLine(
                    $item['type'] ?? 'unknown',
                    $item[$dataKey] ?? [],
                    $line,
                );
                yield $eventLine;
            }
        }
    }
}
