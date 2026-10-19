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
            'type' => 'row',
            'payload' => [
                'foo' => 'bar',
            ],
        ]) . "\n";
        $json2 = json_encode([
            'type' => 'row',
            'payload' => [
                'baz' => 'qux',
            ],
        ]) . "\n";
        $json3 = json_encode([
            'type' => 'row',
            'payload' => 'io.test.v1',
        ]) . "\n";
        $json4 = json_encode([
            'type' => 'row',
            'payload' => 4,
        ]) . "\n";
        $json5 = json_encode([
            'type' => 'row',
            'payload' => 1.2,
        ]) . "\n";
        $json6 = json_encode([
            'type' => 'row',
            'payload' => true,
        ]) . "\n";

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator([$json1, $json2, $json3, $json4, $json5, $json6]));

        $events = iterator_to_array(NdJson::readStream($stream));

        $this->assertCount(6, $events);
        $this->assertInstanceOf(ReadEventLine::class, $events[0]);
        $this->assertSame('row', $events[0]->type);
        $this->assertSame([
            'foo' => 'bar',
        ], $events[0]->payload);

        $this->assertInstanceOf(ReadEventLine::class, $events[1]);
        $this->assertSame('row', $events[1]->type);
        $this->assertSame([
            'baz' => 'qux',
        ], $events[1]->payload);

        $this->assertInstanceOf(ReadEventLine::class, $events[2]);
        $this->assertSame('row', $events[2]->type);
        $this->assertSame('io.test.v1', $events[2]->payload);

        $this->assertInstanceOf(ReadEventLine::class, $events[3]);
        $this->assertSame('row', $events[3]->type);
        $this->assertSame(4, $events[3]->payload);

        $this->assertInstanceOf(ReadEventLine::class, $events[4]);
        $this->assertSame('row', $events[4]->type);
        $this->assertEqualsWithDelta(1.2, $events[4]->payload, PHP_FLOAT_EPSILON);

        $this->assertInstanceOf(ReadEventLine::class, $events[5]);
        $this->assertSame('row', $events[5]->type);
        $this->assertTrue($events[5]->payload);
    }

    public function testReadStreamSkipsEmptyLines(): void
    {
        $jsonLines = json_encode([
            'type' => 'TestEvent',
            'payload' => [],
        ]);

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator(['', $jsonLines, '']));

        $result = iterator_to_array(NdJson::readStream($stream));

        $this->assertCount(1, $result);
        $this->assertSame('TestEvent', $result[0]->type);
    }

    public function testReadEventLineKeepsTheSignatureOfThePreviousRelease(): void
    {
        $readEventLine = new ReadEventLine('row', [
            'foo' => 'bar',
        ]);

        $this->assertSame('row', $readEventLine->type);
        $this->assertSame('', $readEventLine->line);
    }

    public function testReadStreamSkipsLinesThatAreOnlyWhitespace(): void
    {
        $jsonLines = json_encode([
            'type' => 'TestEvent',
            'payload' => [],
        ]) . "\n";

        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator(["\n", $jsonLines, " \t\n"]));

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

    public function testReadStreamReadsEveryLineOfAChunk(): void
    {
        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator(["{\"type\":\"subject\",\"payload\":{\"subject\":\"/a\"}}\n{\"type\":\"subject\",\"payload\":{\"subject\":\"/b\"}}\n"]));

        $result = iterator_to_array(NdJson::readStream($stream), false);

        $this->assertCount(2, $result);
        $this->assertSame([
            'subject' => '/a',
        ], $result[0]->payload);
        $this->assertSame('{"type":"subject","payload":{"subject":"/a"}}', $result[0]->line);
        $this->assertSame([
            'subject' => '/b',
        ], $result[1]->payload);
        $this->assertSame('{"type":"subject","payload":{"subject":"/b"}}', $result[1]->line);
    }

    public function testReadStreamTakesTheDataFromTheGivenMember(): void
    {
        $stream = $this->createMock(Stream::class);
        $stream->method('getIterator')
            ->willReturn(new ArrayIterator(["{\"type\":\"subject\",\"data\":{\"subject\":\"/a\"}}\n"]));

        $result = iterator_to_array(NdJson::readStream($stream, 'data'));

        $this->assertCount(1, $result);
        $this->assertSame('subject', $result[0]->type);
        $this->assertSame([
            'subject' => '/a',
        ], $result[0]->payload);
    }
}
