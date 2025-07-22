<?php

declare(strict_types=1);

namespace Stream;

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

    public function testGetHeadersReturnsProvidedHeaders(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
        ];
        $response = new Response(200, $headers);

        $this->assertSame($headers, $response->getHeaders());
    }

    public function testGetHeaderReturnsEmptyArrayWhenHeaderNotFound(): void
    {
        $response = new Response(200, []);

        $this->assertSame([], $response->getHeader('X-Test'));
    }

    public function testGetHeaderReturnsEmptyArrayWhenHeaderFound(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
        ];
        $response = new Response(200, $headers);

        $this->assertEquals(['application/json'], $response->getHeader('CONTENT-TYPE'));
        $this->assertEquals(['application/json'], $response->getHeader('Content-Type'));
        $this->assertEquals(['application/json'], $response->getHeader('content-type'));
    }

    public function testGetHeaderLineReturnsEmptyStringWhenHeaderNotFound(): void
    {
        $response = new Response(200, []);

        $this->assertSame('', $response->getHeaderLine('X-Test'));
    }

    public function testGetHeaderLineReturnsEmptyStringWhenHeaderFound(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
        ];
        $response = new Response(200, $headers);

        $this->assertSame($headers[1], $response->getHeaderLine('CONTENT-TYPE'));
        $this->assertSame($headers[1], $response->getHeaderLine('Content-Type'));
        $this->assertSame($headers[1], $response->getHeaderLine('content-type'));
    }

    public function testGetStreamReturnsProvidedStream(): void
    {
        $stream = $this->createMock(Stream::class);
        $response = new Response(200, [], $stream);

        $this->assertSame($stream, $response->getStream());
    }
}
