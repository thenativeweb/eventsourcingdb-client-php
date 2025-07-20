<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;
use Thenativeweb\Eventsourcingdb\Stream\ReadEventLine;

final class NdJsonTest extends TestCase
{
    public function getEofReturnValues(string $jsonLines): array
    {
        $byteLine = $this->getReadReturnValues($jsonLines);

        $eofReturnValues = array_fill(0, count($byteLine), false);
        $eofReturnValues[] = true;

        return $eofReturnValues;
    }

    public function getReadReturnValues(string $jsonLines): array
    {
        $eofFixChar = '-';
        $jsonLineCount = substr_count($jsonLines, "\n");
        return str_split($jsonLines . str_repeat($eofFixChar, $jsonLineCount));
    }

    public function testReadLineReadsUntilNewline(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')
            ->willReturnOnConsecutiveCalls(false, false, false, true);

        $stream->method('read')
            ->willReturnOnConsecutiveCalls('f', 'o', "\n");

        $line = NdJson::readLine($stream);
        $this->assertSame("fo\n", $line);
    }

    public function testReadLineReturnsEmptyStringIfNothingToRead(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturn('');

        $line = NdJson::readLine($stream);
        $this->assertSame('', $line);
    }

    public function testReadStreamYieldsEventLines(): void
    {
        $json1 = json_encode([
            'type' => 'event1',
            'payload' => [
                'foo' => 'bar',
            ],
        ]) . "\n";
        $json2 = json_encode([
            'type' => 'event2',
            'payload' => [
                'baz' => 'qux',
            ],
        ]) . "\n";
        $jsonLines = $json1 . $json2;

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')
            ->willReturnOnConsecutiveCalls(...$this->getEofReturnValues($jsonLines));
        $stream->method('read')
            ->willReturnOnConsecutiveCalls(...$this->getReadReturnValues($jsonLines));

        $events = iterator_to_array(NdJson::readStream($stream));

        $this->assertCount(2, $events);
        $this->assertInstanceOf(ReadEventLine::class, $events[0]);
        $this->assertSame('event1', $events[0]->type);
        $this->assertSame([
            'foo' => 'bar',
        ], $events[0]->payload);

        $this->assertInstanceOf(ReadEventLine::class, $events[1]);
        $this->assertSame('event2', $events[1]->type);
        $this->assertSame([
            'baz' => 'qux',
        ], $events[1]->payload);
    }

    public function testReadStreamSkipsEmptyLines(): void
    {
        $jsonLines = json_encode([
            'type' => 'event',
            'payload' => [],
        ]) . "\n";

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')
            ->willReturnOnConsecutiveCalls(...$this->getEofReturnValues($jsonLines));
        $stream->method('read')
            ->willReturnOnConsecutiveCalls(...$this->getReadReturnValues($jsonLines));

        $events = iterator_to_array(NdJson::readStream($stream));

        $this->assertCount(1, $events);
        $this->assertSame('event', $events[0]->type);
    }

    public function testReadStreamThrowsOnInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read events.');

        $jsonLines = "{ invalid json }\n";

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')
            ->willReturnOnConsecutiveCalls(...$this->getEofReturnValues($jsonLines));
        $stream->method('read')
            ->willReturnOnConsecutiveCalls(...$this->getReadReturnValues($jsonLines));

        iterator_to_array(NdJson::readStream($stream));
    }

    public function testReadStreamThrowsIfDecodedJsonIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read events, expected an array.');

        $jsonLines = json_encode('just a string') . "\n";

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')
            ->willReturnOnConsecutiveCalls(...$this->getEofReturnValues($jsonLines));
        $stream->method('read')
            ->willReturnOnConsecutiveCalls(...$this->getReadReturnValues($jsonLines));

        iterator_to_array(NdJson::readStream($stream));
    }
}
