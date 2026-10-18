<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use RuntimeException;

/**
 * Finds the values in a JSON text as they are written there, byte for byte,
 * rather than decoded, so that the data of an event can be hashed the way the
 * server sent it. Decoding and encoding it again would not do, since PHP
 * writes JSON differently, e.g. it escapes slashes and non-ASCII characters.
 *
 * It expects valid JSON, which is checked before, and does not validate it
 * again.
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\RawJsonTest
 */
final class RawJson
{
    private const WHITESPACE = " \t\n\r";

    /**
     * Returns the raw value of every member of a JSON object, by its name.
     *
     * @return array<string, string>
     */
    public static function getMembers(string $json): array
    {
        $offset = self::expect($json, self::skipWhitespace($json, 0), '{');

        $members = [];
        $offset = self::skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            return $members;
        }

        while (true) {
            $nameStart = self::skipWhitespace($json, $offset);
            $nameEnd = self::skipString($json, $nameStart);
            $name = json_decode(substr($json, $nameStart, $nameEnd - $nameStart));
            if (!is_string($name)) {
                throw new RuntimeException('Failed to read the name of a JSON member.');
            }

            $valueStart = self::skipWhitespace($json, self::expect($json, self::skipWhitespace($json, $nameEnd), ':'));
            $valueEnd = self::skipValue($json, $valueStart);
            $members[$name] = substr($json, $valueStart, $valueEnd - $valueStart);

            $offset = self::skipWhitespace($json, $valueEnd);
            if (($json[$offset] ?? '') === '}') {
                return $members;
            }

            $offset = self::expect($json, $offset, ',');
        }
    }

    /**
     * Returns the raw value of every element of a JSON array, in order.
     *
     * @return list<string>
     */
    public static function getElements(string $json): array
    {
        $offset = self::expect($json, self::skipWhitespace($json, 0), '[');

        $elements = [];
        $offset = self::skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            return $elements;
        }

        while (true) {
            $valueStart = self::skipWhitespace($json, $offset);
            $valueEnd = self::skipValue($json, $valueStart);
            $elements[] = substr($json, $valueStart, $valueEnd - $valueStart);

            $offset = self::skipWhitespace($json, $valueEnd);
            if (($json[$offset] ?? '') === ']') {
                return $elements;
            }

            $offset = self::expect($json, $offset, ',');
        }
    }

    private static function skipValue(string $json, int $offset): int
    {
        $character = $json[$offset] ?? '';

        if ($character === '"') {
            return self::skipString($json, $offset);
        }

        if ($character === '{' || $character === '[') {
            return self::skipStructure($json, $offset);
        }

        // A number, true, false, or null ends where whitespace, a comma, or
        // the end of the enclosing object or array starts.
        $length = $offset < strlen($json) ? strcspn($json, self::WHITESPACE . ',]}', $offset) : 0;
        if ($length === 0) {
            throw new RuntimeException('Failed to read a JSON value, since it is missing.');
        }

        return $offset + $length;
    }

    private static function skipString(string $json, int $offset): int
    {
        $offset = self::expect($json, $offset, '"');
        $length = strlen($json);

        while ($offset < $length) {
            $offset += strcspn($json, '"\\', $offset);
            if ($offset >= $length) {
                break;
            }

            if ($json[$offset] === '"') {
                return $offset + 1;
            }

            // A backslash escapes the character after it, which may be a
            // quote, so that one does not end the string.
            $offset += 2;
        }

        throw new RuntimeException('Failed to read a JSON string, since it does not end.');
    }

    private static function skipStructure(string $json, int $offset): int
    {
        $length = strlen($json);
        $depth = 0;

        while ($offset < $length) {
            $offset += strcspn($json, '"{}[]', $offset);
            if ($offset >= $length) {
                break;
            }

            $character = $json[$offset];
            if ($character === '"') {
                $offset = self::skipString($json, $offset);

                continue;
            }

            $depth += $character === '{' || $character === '[' ? 1 : -1;
            ++$offset;

            if ($depth === 0) {
                return $offset;
            }
        }

        throw new RuntimeException('Failed to read a JSON object or array, since it does not end.');
    }

    private static function skipWhitespace(string $json, int $offset): int
    {
        if ($offset >= strlen($json)) {
            return $offset;
        }

        return $offset + strspn($json, self::WHITESPACE, $offset);
    }

    private static function expect(string $json, int $offset, string $character): int
    {
        if (($json[$offset] ?? '') !== $character) {
            throw new RuntimeException("Failed to read JSON, since '{$character}' is missing at offset {$offset}.");
        }

        return $offset + 1;
    }
}
