<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\CurlMultiHandler;
use Thenativeweb\Eventsourcingdb\Stream\Stream;

final class StreamTest extends TestCase
{
    public function testGetIteratorYieldsChunks(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['chunk1', 'chunk2']));

        $stream = new Stream($mockHandler);

        $chunks = iterator_to_array($stream);
        $this->assertSame(['chunk1', 'chunk2'], $chunks);
    }

    public function testGetContentsConcatenatesAllChunks(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['foo', 'bar']));

        $stream = new Stream($mockHandler);

        $this->assertSame('foobar', $stream->getContents());
    }

    public function testToStringReturnsContents(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['foo', 'bar']));

        $stream = new Stream($mockHandler);

        $this->assertSame('foobar', (string) $stream);
    }

    public function testThrowsExceptionOnInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid json string');

        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['{invalid json']));

        $stream = new Stream($mockHandler);
        $stream->getJsonData();
    }

    public function testThrowsExceptionIfJsonIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("json data is from type 'boolean', expected an array");

        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['true']));

        $stream = new Stream($mockHandler);
        $stream->getJsonData();
    }

    public function testReturnsEmptyArrayOnEmptyContents(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator([]));

        $stream = new Stream($mockHandler);

        $this->assertSame([], $stream->getJsonData());
    }

    public function testReturnsDecodedArrayIfValidJsonArray(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->method('contentIterator')
            ->willReturn(new ArrayIterator(['{"foo":"bar"}']));

        $stream = new Stream($mockHandler);

        $this->assertSame([
            'foo' => 'bar',
        ], $stream->getJsonData());
    }
}
