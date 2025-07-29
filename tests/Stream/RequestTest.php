<?php

declare(strict_types=1);

namespace Stream;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Request;
use Thenativeweb\Eventsourcingdb\Stream\Uri;

final class RequestTest extends TestCase
{
    public function testConstructorInitializesPropertiesCorrectly(): void
    {
        $request = new Request(
            'post',
            'https://example.com/path',
            [
                'Content-Type: application/json',
            ],
            '{"foo":"bar"}',
            '2.0'
        );

        $this->assertSame('POST', $request->getMethod());
        $this->assertInstanceOf(Uri::class, $request->getUri());
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('2.0', $request->getProtocolVersion());
        $this->assertSame([
            'Content-Type: application/json',
        ], $request->getHeaders());
        $this->assertSame('{"foo":"bar"}', $request->getBody());
    }

    public function testGetMethodReturnsUppercase(): void
    {
        $request = new Request('get', 'https://example.com');
        $this->assertSame('GET', $request->getMethod());
    }

    public function testGetBodyReturnsNullIfNotSet(): void
    {
        $request = new Request('GET', 'https://example.com');
        $this->assertNull($request->getBody());
    }

    public function testGetProtocolVersionDefaultsTo11(): void
    {
        $request = new Request('GET', 'https://example.com');
        $this->assertSame('1.1', $request->getProtocolVersion());
    }
}
