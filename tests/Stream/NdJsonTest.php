<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\NdJson;
use Thenativeweb\Eventsourcingdb\Stream\ReadEventLine;
use Thenativeweb\Eventsourcingdb\Stream\Stream;

final class NdJsonTest extends TestCase
{
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

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator([$json1, $json2]));

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
        $jsonLines = json_encode(['type' => 'TestEvent', 'payload' => []]);

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator(['', $jsonLines, '']));

        $result = iterator_to_array(NdJson::readStream($stream));

        $this->assertCount(1, $result);
        $this->assertSame('TestEvent', $result[0]->type);
    }

    public function testReadStreamThrowsOnInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read events, when processing the ndjson.');

        $jsonLines = "{ invalid json }\n";

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator([$jsonLines]));

        iterator_to_array(NdJson::readStream($stream));
    }

    public function testReadStreamThrowsIfDecodedJsonIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to read events, expected an array.');

        $jsonLines = json_encode('just a string') . "\n";

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator([$jsonLines]));

        iterator_to_array(NdJson::readStream($stream));
    }
}
