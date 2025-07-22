<?php

declare(strict_types=1);

namespace Stream;

use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Stream\Header;

final class HttpClientTest extends TestCase
{
    public function testBuildUriWithBaseUrl(): void
    {
        $httpClient = new HttpClient('https://example.com');
        $uri = $httpClient->buildUri('/test');

        $this->assertEquals('https://example.com/test', $uri);
    }

    public function testBuildUriWithoutBaseUrl(): void
    {
        $client = new HttpClient();
        $uri = $client->buildUri('test');

        $this->assertEquals('test', $uri);
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
        $this->assertEquals(200, $header->statusCode);
        $this->assertEquals('1.1', $header->httpVersion);
        $this->assertEquals('application/json', $header->contentType);
        $this->assertEquals(123, $header->contentLength);
    }

    #[DataProvider('callContentTypes')]
    public function testIsContentTypeSupportedWithValidType(string $contentType, bool $expected): void
    {
        $httpClient = new HttpClient();
        $isSupported = $httpClient->isContentTypeSupported($contentType);

        $this->assertEquals($expected, $isSupported, "Content type '{$contentType}' should be " . ($expected ? 'supported' : 'not supported'));
    }

    public static function callContentTypes(): array
    {
        return [
            'application/json' => [
                'application/json',
                true,
            ],
            'application/json and charset' => [
                'application/json; charset=utf-8',
                true,
            ],
            'application/x-ndjson' => [
                'application/x-ndjson',
                true,
            ],
            'application/x-ndjson and charset' => [
                'application/x-ndjson; charset=utf-8',
                true,
            ],
            'text/plain' => [
                'text/plain',
                true,
            ],
            'text/plain and charset' => [
                'text/plain; charset=utf-8',
                true,
            ],
            'text/json' => [
                'text/json',
                false,
            ],
            'text/x-ndjson' => [
                'text/x-ndjson',
                false,
            ],
        ];
    }
}
