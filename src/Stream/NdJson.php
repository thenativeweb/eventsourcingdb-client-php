<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use RuntimeException;

final readonly class NdJson
{
    public static function readStream(Stream $stream): iterable
    {
        foreach($stream as $chunk) {
            $line = $chunk;
            if ($line === '') {
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
                $item['payload'] ?? [],
            );
            yield $eventLine;
        }
    }
}
