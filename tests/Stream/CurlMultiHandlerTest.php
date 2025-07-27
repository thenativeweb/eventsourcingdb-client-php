<?php

declare(strict_types=1);

namespace Stream;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\CurlMultiHandler;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Stream\Request;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class CurlMultiHandlerTest extends TestCase
{
    use ClientTestTrait;

    public function getPropertyValue(object $object, string $propertyName): mixed
    {
        $reflectionClass = new ReflectionClass($object);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($object);
    }

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
        $request = $this->createMock(Request::class);

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->addHandle($request);

        $this->assertInstanceOf(Queue::class, $this->getPropertyValue($curlMultiHandler, 'header'));
        $this->assertInstanceOf(Queue::class, $this->getPropertyValue($curlMultiHandler, 'write'));
        $this->assertNotNull($this->getPropertyValue($curlMultiHandler, 'handle'));
    }

    public function testExecuteThrowsIfHandleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No handle to execute.');

        $curlMultiHandler = new CurlMultiHandler();
        $curlMultiHandler->execute();
    }

    public function testExecuteThrowsIfHostNotExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("#Internal HttpClient: cURL handle execution failed with error: Failed to connect to [^ ]+ port 1234 after \d+ ms: Couldn't connect to server#");

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

    public function testContentIteratorThrowsIfMultiHandleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No multi handle to execute.');

        $curlMultiHandler = new CurlMultiHandler();
        iterator_count($curlMultiHandler->contentIterator());
    }

    public function testContentIteratorThrowsIfWriteQueueMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal HttpClient: No write queue available.');

        $curlMultiHandler = new CurlMultiHandler();

        $reflectionClass = new ReflectionClass($curlMultiHandler);
        $reflectionProperty = $reflectionClass->getProperty('multiHandle');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($curlMultiHandler, curl_multi_init());

        iterator_count($curlMultiHandler->contentIterator());
    }
}
