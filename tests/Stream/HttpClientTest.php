<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Header;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\Queue;

final class HttpClientTest extends TestCase
{
    public function testBuildUriWithBaseUrl(): void
    {
        $httpClient = new HttpClient('https://example.com');
        $uri = $httpClient->buildUri('/test');

        $this->assertSame('https://example.com/test', $uri);
    }

    public function testBuildUriWithoutBaseUrl(): void
    {
        $httpClient = new HttpClient();
        $uri = $httpClient->buildUri('test');

        $this->assertSame('test', $uri);
    }

    public function testParseHeaderQueueParsesCorrectly(): void
    {
        $queueMock = $this->createMock(Queue::class);
        $queueMock->method('getIterator')
            ->willReturn(
                new ArrayIterator(
                    [
                        'HTTP/1.1 200 OK',
                        'Content-Type: application/json',
                        'Content-Length: 123',
                    ],
                )
            );

        $httpClient = new HttpClient();
        $header = $httpClient->parseHeaderQueue($queueMock);

        $this->assertInstanceOf(Header::class, $header);
        $this->assertSame(200, $header->statusCode);
        $this->assertSame('1.1', $header->httpVersion);
        $this->assertSame('application/json', $header->contentType);
        $this->assertSame(123, $header->contentLength);
    }

    #[DataProvider('callContentTypes')]
    public function testIsContentTypeSupportedWithValidType(string $contentType, bool $expected): void
    {
        $httpClient = new HttpClient();
        $isSupported = $httpClient->isContentTypeSupported($contentType);

        $this->assertSame($expected, $isSupported, "Content type '{$contentType}' should be " . ($expected ? 'supported' : 'not supported'));
    }

    public static function callContentTypes(): \Iterator
    {
        yield 'application/json' => [
            'application/json',
            true,
        ];
        yield 'application/json and charset' => [
            'application/json; charset=utf-8',
            true,
        ];
        yield 'application/x-ndjson' => [
            'application/x-ndjson',
            true,
        ];
        yield 'application/x-ndjson and charset' => [
            'application/x-ndjson; charset=utf-8',
            true,
        ];
        yield 'text/plain' => [
            'text/plain',
            true,
        ];
        yield 'text/plain and charset' => [
            'text/plain; charset=utf-8',
            true,
        ];
        yield 'text/json' => [
            'text/json',
            false,
        ];
        yield 'text/x-ndjson' => [
            'text/x-ndjson',
            false,
        ];
    }
}
