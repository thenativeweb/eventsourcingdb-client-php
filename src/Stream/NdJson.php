<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final readonly class NdJson
{
    public static function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (!$stream->eof()) {
            if ('' === ($byte = $stream->read(1))) {
                return $buffer;
            }

            $buffer .= $byte;
            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    public static function readStream(StreamInterface $stream): iterable
    {
        while (!$stream->eof()) {
            $line = self::readLine($stream);
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
