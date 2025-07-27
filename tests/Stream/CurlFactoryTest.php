<?php

declare(strict_types=1);

namespace Stream;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\CurlFactory;
use Thenativeweb\Eventsourcingdb\Stream\FileUpload;
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
        $this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYPEER, $options);
        $this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYHOST, $options);
        $this->assertArrayNotHasKey(CURLOPT_TIMEOUT, $options);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        $this->assertArrayNotHasKey(CURLOPT_ENCODING, $options);
        $this->assertArrayNotHasKey(CURLOPT_NOBODY, $options);
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

    public function testCreateSetsPostFieldsIfBodyFileUpload(): void
    {
        $fileUpload = $this->createMock(FileUpload::class);
        $fileUpload->method('getSize')->willReturn(123);
        $fileUpload->method('read')->willReturn('chunk');

        $this->uriMock->method('__toString')->willReturn('https://example.com/upload');
        $this->uriMock->method('getScheme')->willReturn('https');

        $this->requestMock->method('getMethod')->willReturn('POST');
        $this->requestMock->method('getProtocolVersion')->willReturn('1.1');
        $this->requestMock->method('getHeaders')->willReturn([]);
        $this->requestMock->method('getUri')->willReturn($this->uriMock);
        $this->requestMock->method('getBody')->willReturn($fileUpload);
        $this->requestMock->method('hasHeader')->willReturn(false);

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertArrayHasKey(CURLOPT_UPLOAD, $options);
        $this->assertTrue($options[CURLOPT_UPLOAD]);

        $this->assertArrayHasKey(CURLOPT_INFILESIZE, $options);
        $this->assertSame(123, $options[CURLOPT_INFILESIZE]);

        $this->assertArrayHasKey(CURLOPT_READFUNCTION, $options);
        $readFn = $options[CURLOPT_READFUNCTION];
        $this->assertIsCallable($readFn);

        $this->assertSame('chunk', $readFn(null));
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
        $this->assertSame(strlen('test-header'), $length);
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
        $this->assertSame(strlen('test-chunk'), $length);
    }

    public function testCreateSetsPostFieldsIfAcceptEncodingExists(): void
    {
        $acceptEncoding = ['gzip', 'deflate'];

        $this->requestMock->method('hasHeader')->willReturn(true);
        $this->requestMock->method('getHeader')->willReturn($acceptEncoding);

        $options = CurlFactory::create(
            $this->requestMock,
            $this->headerQueueMock,
            $this->writeQueueMock,
        );

        $this->assertSame(implode(',', $acceptEncoding), $options[CURLOPT_ENCODING]);
    }
}
