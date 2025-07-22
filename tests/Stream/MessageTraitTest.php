<?php

declare(strict_types=1);

namespace Stream;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Tests\Stream\MockClass\MockMessageTrait;

final class MessageTraitTest extends TestCase
{
    public function testGetHeadersReturnsProvidedHeaders(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
        ];

        $mockMessageTrait = new MockMessageTrait($headers);

        $this->assertSame($headers, $mockMessageTrait->getHeaders());
    }

    public function testGetHeaderReturnsEmptyArrayWhenHeaderNotFound(): void
    {
        $mockMessageTrait = new MockMessageTrait([]);

        $this->assertSame([], $mockMessageTrait->getHeader('X-Test'));
    }

    public function testGetHeaderReturnsArrayWhenHeaderFound(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
            'Accept-Encoding: gzip, deflate',
        ];
        $mockMessageTrait = new MockMessageTrait($headers);

        $this->assertSame(['application/json'], $mockMessageTrait->getHeader('CONTENT-TYPE'));
        $this->assertSame(['application/json'], $mockMessageTrait->getHeader('Content-Type'));
        $this->assertSame(['application/json'], $mockMessageTrait->getHeader('content-type'));
    }

    public function testGetHeaderReturnsArrayWhenHeaderFoundWithMultiData(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
            'Accept-Encoding: gzip, deflate',
        ];
        $mockMessageTrait = new MockMessageTrait($headers);

        $this->assertSame(['gzip', 'deflate'], $mockMessageTrait->getHeader('ACCEPT-ENCODING'));
        $this->assertSame(['gzip', 'deflate'], $mockMessageTrait->getHeader('Accept-Encoding'));
        $this->assertSame(['gzip', 'deflate'], $mockMessageTrait->getHeader('accept-encoding'));
    }

    public function testGetHeaderLineReturnsEmptyStringWhenHeaderNotFound(): void
    {
        $mockMessageTrait = new MockMessageTrait([]);

        $this->assertSame('', $mockMessageTrait->getHeaderLine('X-Test'));
    }

    public function testGetHeaderLineReturnsStringWhenHeaderFound(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
            'Accept-Encoding: gzip, deflate',
        ];
        $mockMessageTrait = new MockMessageTrait($headers);

        $this->assertSame($headers[1], $mockMessageTrait->getHeaderLine('CONTENT-TYPE'));
        $this->assertSame($headers[1], $mockMessageTrait->getHeaderLine('Content-Type'));
        $this->assertSame($headers[1], $mockMessageTrait->getHeaderLine('content-type'));
    }

    public function testGetHeaderLineReturnsStringWhenHeaderFoundWithMultiData(): void
    {
        $headers = [
            'Authorization: Bearer token',
            'Content-Type: application/json',
            'Accept-Encoding: gzip, deflate',
        ];
        $mockMessageTrait = new MockMessageTrait($headers);

        $this->assertSame($headers[2], $mockMessageTrait->getHeaderLine('ACCEPT-ENCODING'));
        $this->assertSame($headers[2], $mockMessageTrait->getHeaderLine('Accept-Encoding'));
        $this->assertSame($headers[2], $mockMessageTrait->getHeaderLine('accept-encoding'));
    }
}
