<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Response;
use Thenativeweb\Eventsourcingdb\Stream\Stream;

final class ResponseTest extends TestCase
{
    public function testConstructWithValidStatusCode(): void
    {
        $response = new Response();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    public function testConstructWithInvalidStatusCodeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Internal HttpClient: The status code 999 must be one of the defined HTTP status codes.');

        new Response(999);
    }

    public function testGetStreamReturnsProvidedStream(): void
    {
        $stream = $this->createStub(Stream::class);
        $response = new Response(200, [], $stream);

        $this->assertSame($stream, $response->getStream());
    }
}
