<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\CurlFactory;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Stream\Request;
use Thenativeweb\Eventsourcingdb\Stream\Uri;

final class CurlFactoryTest extends TestCase
{
    private MockObject $requestMock;
    private MockObject $headerQueueMock;
    private MockObject $writeQueueMock;
    private MockObject $uriMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestMock = $this->createMock(Request::class);
        $this->headerQueueMock = $this->createMock(Queue::class);
        $this->writeQueueMock = $this->createMock(Queue::class);
        $this->uriMock = $this->createMock(Uri::class);

        $this->requestMock->method('getUri')->willReturn($this->uriMock);
    }

    public function testCreateReturnsDefaultOptionsForHttp10(): void
    {
        $this->requestMock->method('getProtocolVersion')->willReturn('1.0');
        $this->requestMock->method('getMethod')->willReturnCallback(static fn (): string => 'GET');
        $this->requestMock->method('getHeaders')->willReturn(['X-Test: value']);
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertIsArray($options);
        $this->assertSame(CURL_HTTP_VERSION_1_0, $options[CURLOPT_HTTP_VERSION]);
        $this->assertSame('GET', $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertSame(['X-Test: value'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testCreateSetsHttpVersion20(): void
    {
        $this->requestMock->method('getProtocolVersion')->willReturn('2.0');
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertSame(CURL_HTTP_VERSION_2_0, $options[CURLOPT_HTTP_VERSION]);
    }

    public function testCreateSetsSslOptionsForHttps(): void
    {
        $this->requestMock->method('getProtocolVersion')->willReturn('1.1');
        $this->uriMock->method('__toString')->willReturn('https://example.com');
        $this->uriMock->method('getScheme')->willReturn('https');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertArrayHasKey(CURLOPT_SSL_VERIFYPEER, $options);
        $this->assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertFalse($options[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testCreateSetsTimeout(): void
    {
        $this->requestMock->method('getProtocolVersion')->willReturn('1.1');
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
            timeout: 10,
        );

        $this->assertSame(10, $options[CURLOPT_TIMEOUT]);
    }

    public function testCreateSetsPostFieldsIfBodyExists(): void
    {
        $body = '{"key":"value"}';

        $this->requestMock->method('getBody')->willReturnCallback(static fn (): string => $body);
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertSame($body, $options[CURLOPT_POSTFIELDS]);
    }

    public function testCreateSetsNoBodyForHeadMethod(): void
    {
        $this->requestMock->method('getMethod')->willReturnCallback(static fn (): string => 'HEAD');
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertTrue($options[CURLOPT_NOBODY]);
        $this->assertArrayNotHasKey(CURLOPT_WRITEFUNCTION, $options);
    }

    public function testHeaderFunctionWritesToQueue(): void
    {
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $this->headerQueueMock->expects($this->once())
            ->method('write')
            ->with('test-header');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $headerFunction = $options[CURLOPT_HEADERFUNCTION];
        $length = $headerFunction(null, 'test-header');
        $this->assertEquals(strlen('test-header'), $length);
    }

    public function testWriteFunctionWritesToQueue(): void
    {
        $this->uriMock->method('__toString')->willReturn('http://example.com');
        $this->uriMock->method('getScheme')->willReturn('http');

        $this->writeQueueMock->expects($this->once())
            ->method('write')
            ->with('test-chunk');

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $writeFunction = $options[CURLOPT_WRITEFUNCTION];
        $length = $writeFunction(null, 'test-chunk');
        $this->assertEquals(strlen('test-chunk'), $length);
    }
}
