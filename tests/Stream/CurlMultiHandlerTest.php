<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\CurlMultiHandler;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Stream\Request;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;

final class CurlMultiHandlerTest extends TestCase
{
    use ClientTestTrait;
    use ReflectionTestTrait;

    public function removeLineBrakes(string $line): string
    {
        return preg_replace('/\r\n|\r|\n/', '', $line);
    }

    public function testAbortInWithPositiveValue(): void
    {
        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->abortIn(5.5);

        $this->assertEqualsWithDelta(5.5, $this->getPropertyValue($curlMultiHandler, 'abortIn'), PHP_FLOAT_EPSILON);
        $this->assertIsFloat($this->getPropertyValue($curlMultiHandler, 'iteratorTime'));
    }

    public function testAbortInWithNegativeValue(): void
    {
        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->abortIn(-3.3);

        $this->assertEqualsWithDelta(0.0, $this->getPropertyValue($curlMultiHandler, 'abortIn'), PHP_FLOAT_EPSILON);
        $this->assertIsFloat($this->getPropertyValue($curlMultiHandler, 'iteratorTime'));
    }

    public function testGetHeaderQueueThrowsWithoutQueue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No header queue available.');

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->getHeaderQueue();
    }

    public function testGetWriteQueueThrowsWithoutQueue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No write queue available.');

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->getWriteQueue();
    }

    public function testAddHandleSetsQueuesAndHandle(): void
    {
        $request = $this->createStub(Request::class);

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->addHandle($request);

        $this->assertInstanceOf(Queue::class, $this->getPropertyValue($curlMultiHandler, 'header'));
        $this->assertInstanceOf(Queue::class, $this->getPropertyValue($curlMultiHandler, 'write'));
        $this->assertNotNull($this->getPropertyValue($curlMultiHandler, 'curlHandle'));
    }

    public function testExecuteThrowsIfHandleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No handle available.');

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->execute();
    }

    public function testExecuteThrowsIfHostNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("#Internal HttpClient: cURL handle execution failed with error: Failed to connect to [^ ]+ port 1234 after \d+ ms: [^ ]+#");

        $host = $this->container->getHost();
        $baseUrl = "http://{$host}:1234";

        $request = new Request(
            'GET',
            $baseUrl,
        );
        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->addHandle($request);
        $curlMultiHandler->execute();
    }

    public function testExecuteSendsRequestAndParsesHttpHeadersCorrectly(): void
    {
        $request = new Request(
            'GET',
            $this->container->getBaseUrl() . '/api/v1/ping',
        );
        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->addHandle($request);
        $curlMultiHandler->execute();

        $headerQueue = $curlMultiHandler->getHeaderQueue();

        $this->assertGreaterThanOrEqual(8, $headerQueue->getIterator()->count());
        $this->assertSame('HTTP/1.1 200 OK', $this->removeLineBrakes($headerQueue->read()));
        $this->assertSame('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate', $this->removeLineBrakes($headerQueue->read()));
        $this->assertSame('Content-Type: application/json', $this->removeLineBrakes($headerQueue->read()));
    }

    public function testContentIteratorThrowsIfHandleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No handle available.');

        $curlMultiHandler = new CurlMultiHandler();
        iterator_count($curlMultiHandler->contentIterator());
    }

    public function testContentIteratorThrowsIfMultiHandleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No multi handle available.');

        $curlMultiHandler = new CurlMultiHandler();

        $this->setPropertyValue($curlMultiHandler, 'curlHandle', curl_init());

        iterator_count($curlMultiHandler->contentIterator());
    }

    public function testContentIteratorThrowsIfWriteQueueMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No write queue available.');

        $curlMultiHandler = new CurlMultiHandler();

        $this->setPropertyValue($curlMultiHandler, 'curlHandle', curl_init());
        $this->setPropertyValue($curlMultiHandler, 'curlMultiHandle', curl_multi_init());

        iterator_count($curlMultiHandler->contentIterator());
    }
}
