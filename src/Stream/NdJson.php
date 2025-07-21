<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use RuntimeException;
use Thenativeweb\Eventsourcingdb\HttpClient\StreamInterface;

final readonly class NdJson
{
    public static function readStream(StreamInterface $stream): iterable
    {
        foreach($stream as $chunk) {
            $line = $chunk;
            if ($line === '') {
                continue;
            }

            if (!json_validate($line)) {
                throw new RuntimeException('Failed to read events.');
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
