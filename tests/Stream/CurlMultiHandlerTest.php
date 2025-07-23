<?php

declare(strict_types=1);

namespace Stream;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\CurlMultiHandler;
use Thenativeweb\Eventsourcingdb\Stream\Queue;
use Thenativeweb\Eventsourcingdb\Stream\Request;

final class CurlMultiHandlerTest extends TestCase
{
    public function getPropertyValue(object $object, string $propertyName): mixed
    {
        $reflectionClass = new ReflectionClass($object);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($object);
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
