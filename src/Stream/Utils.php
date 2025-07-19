<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final readonly class Utils
{
    // $blueprint = [
    //     'type' => 'string',
    //     'payload' => [
    //         'error' => 'string',
    //     ],
    // ];
    //
    // $blueprint = [
    //     'type' => 'string',
    //     'payload' => [
    //         'specversion' => 'string',
    //         'id' => 'string',
    //         'time' => 'string',
    //         'source' => 'string',
    //         'subject' => 'string',
    //         'type' => 'string',
    //         'datacontenttype' => 'string',
    //         'data' => 'array',
    //         'hash' => 'string',
    //         'predecessorhash' => 'string',
    //     ],
    // ];
    // public static function hasShapeOf(array $data, array $blueprint): bool
    // {
    //     foreach ($blueprint as $key => $type) {
    //         if (!array_key_exists($key, $data)) {
    //             return false;
    //         }
    //
    //         if (is_array($type)) {
    //             if (!is_array($data[$key])) {
    //                 return false;
    //             }
    //
    //             if (!self::hasShapeOf($data[$key], $type)) {
    //                 return false;
    //             }
    //         } else {
    //             if (gettype($data[$key]) !== $type) {
    //                 return false;
    //             }
    //         }
    //     }
    //
    //     return true;
    // }

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

    /**
     * @return iterable<ReadEventLine>
     */
    public static function readNdJson(StreamInterface $stream): iterable
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
