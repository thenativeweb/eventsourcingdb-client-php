<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use ArrayIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Header;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class HttpClientTest extends TestCase
{
    use ServerTestTrait;

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

    public function testReturnsOnlyAuthorizationHeader(): void
    {
        $httpClient = new HttpClient();
        $headers = $httpClient->buildHeaders('secret');

        $this->assertCount(2, $headers);
        $this->assertContains('Expect:', $headers, 'Ignore curl response header 100-continue');
        $this->assertContains('Authorization: Bearer secret', $headers);
    }

    public function testReturnsAuthorizationAndJsonHeaderWithArrayBody(): void
    {
        $httpClient = new HttpClient();
        $headers = $httpClient->buildHeaders('secret', [
            'foo' => 'bar',
        ]);

        $this->assertCount(3, $headers);
        $this->assertContains('Expect:', $headers, 'Ignore curl response header 100-continue');
        $this->assertContains('Authorization: Bearer secret', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function testReturnsJsonHeaderWithoutToken(): void
    {
        $httpClient = new HttpClient();
        $headers = $httpClient->buildHeaders(null, [
            'data' => 123,
        ]);

        $this->assertCount(2, $headers);
        $this->assertContains('Expect:', $headers, 'Ignore curl response header 100-continue');
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function testReturnsEmptyHeadersWhenNoArgumentsGiven(): void
    {
        $httpClient = new HttpClient();
        $headers = $httpClient->buildHeaders(null);
        $this->assertSame(['Expect:'], $headers);
    }

    public function testReturnsEmptyStringForNull(): void
    {
        $httpClient = new HttpClient();
        $result = $httpClient->buildBody(null);
        $this->assertSame('', $result);
    }

    public function testReturnsJsonEncodedStringForArray(): void
    {
        $array = [
            'foo' => 'bar',
        ];

        $httpClient = new HttpClient();
        $result = $httpClient->buildBody($array);
        $this->assertSame(json_encode($array), $result);
    }

    public function testReturnsJsonEncodedStringForObject(): void
    {
        $obj = (object) [
            'baz' => 'qux',
        ];

        $httpClient = new HttpClient();
        $result = $httpClient->buildBody($obj);
        $this->assertSame(json_encode($obj), $result);
    }

    public function testHandsOverTheResponseAsSoonAsTheHeadersHaveArrived(): void
    {
        // The server answers after a short delay, so that the client is
        // already waiting for the response when the headers arrive.
        $delay = 0.1;
        $address = $this->startServer([], interval: 0.0, holdFor: 5.0, delay: $delay);

        $httpClient = new HttpClient("http://{$address}");

        $startTime = microtime(true);
        $response = $httpClient->post('/api/v1/read-events', 'secret', [
            'subject' => '/',
        ]);
        $processTime = microtime(true) - $startTime - $delay;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertLessThan(0.5, $processTime, "Expected the response to be handed over right away, but it took {$processTime} seconds after the headers.");
    }

    public function testWaitsForAllHeadersOfTheResponse(): void
    {
        // The server sends the status line first and the other headers a
        // little later, so that they arrive in separate packets.
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 5.0, headerDelay: 0.2),
        ]);

        $httpClient = new HttpClient("http://{$address}");

        $response = $httpClient->post('/api/v1/read-events', 'secret', [
            'subject' => '/',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['EventSourcingDB/test'], $response->getHeader('Server'));
        $this->assertSame(['application/x-ndjson'], $response->getHeader('Content-Type'));
    }

    public function testClosesTheConnectionIfTheContentTypeIsNotSupported(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 5.0, contentType: 'text/html'),
        ]);

        $httpClient = new HttpClient("http://{$address}");

        try {
            $httpClient->post('/api/v1/read-events', 'secret', [
                'subject' => '/',
            ]);
            $this->fail('Expected the response to be refused, but it was not.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringStartsWith("Internal HttpClient: got Content-Type 'text/html'", $invalidArgumentException->getMessage());
        }

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }
}
