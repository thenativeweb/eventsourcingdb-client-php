<?php

declare(strict_types=1);

namespace Stream;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
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

        $chunks = iterator_to_array($stream->getIterator());
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

    public function testCancelSetsStreamTimeout(): void
    {
        $mockHandler = $this->createMock(CurlMultiHandler::class);
        $mockHandler->expects($this->once())
            ->method('setStreamTimeout')
            ->with(5.0);

        $stream = new Stream($mockHandler);
        $stream->cancel(5.0);
    }
}
