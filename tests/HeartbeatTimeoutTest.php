<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\HeartbeatTimeoutException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class HeartbeatTimeoutTest extends TestCase
{
    use ReflectionTestTrait;
    use ServerTestTrait;

    private const HEARTBEAT_TIMEOUT = 0.5;
    private const HEARTBEAT_LINE = '{"type":"heartbeat","payload":{}}';

    public static function callsWithHeartbeats(): Iterator
    {
        yield 'observeEvents' => [
            static fn (Client $client): iterable => $client->observeEvents('/', new ObserveEventsOptions(recursive: true)),
            json_encode([
                'type' => 'event',
                'payload' => [
                    'specversion' => '1.0',
                    'id' => '0',
                    'time' => '2026-01-01T00:00:00Z',
                    'source' => 'https://www.eventsourcingdb.io',
                    'subject' => '/test',
                    'type' => 'io.eventsourcingdb.test',
                    'datacontenttype' => 'application/json',
                    'data' => [
                        'value' => 23,
                    ],
                    'hash' => 'hash',
                    'predecessorhash' => 'predecessorhash',
                    'signature' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ];
        yield 'runEventQlQuery' => [
            static fn (Client $client): iterable => $client->runEventQlQuery('FROM e IN events PROJECT INTO e'),
            json_encode([
                'type' => 'row',
                'payload' => [
                    'value' => 23,
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public static function callsWithoutHeartbeats(): Iterator
    {
        yield 'readEvents' => [
            static fn (Client $client): iterable => $client->readEvents('/', new ReadEventsOptions(recursive: true)),
        ];
        yield 'readSubjects' => [
            static fn (Client $client): iterable => $client->readSubjects('/'),
        ];
        yield 'readEventTypes' => [
            static fn (Client $client): iterable => $client->readEventTypes(),
        ];
    }

    public function testUsesAHeartbeatTimeoutOfThirtySeconds(): void
    {
        $client = new Client('http://localhost:3000', 'secret');

        $httpClient = $this->getPropertyValue($client, 'httpClient');
        $curlMultiHandler = $this->getPropertyValue($httpClient, 'curlMultiHandler');

        $this->assertEqualsWithDelta(30.0, $this->getPropertyValue($curlMultiHandler, 'heartbeatTimeout'), PHP_FLOAT_EPSILON);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithAHeartbeatTimeoutIfNeitherAnEventNorAHeartbeatArrives(Closure $call): void
    {
        $client = $this->startClient([self::HEARTBEAT_LINE], interval: 0.0, holdFor: 5.0);

        $startTime = microtime(true);
        try {
            iterator_count($call($client));
            $this->fail('Expected the stream to end with a heartbeat timeout, but it ended without one.');
        } catch (HeartbeatTimeoutException $heartbeatTimeoutException) {
            $this->assertSame('No event and no heartbeat arrived for 0.5 seconds.', $heartbeatTimeoutException->getMessage());
        }

        $processTime = microtime(true) - $startTime;

        $this->assertGreaterThanOrEqual(self::HEARTBEAT_TIMEOUT, $processTime);
        $this->assertLessThan(self::HEARTBEAT_TIMEOUT + 1.0, $processTime);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testKeepsReadingWhileHeartbeatsArrive(Closure $call): void
    {
        $client = $this->startClient(array_fill(0, 20, self::HEARTBEAT_LINE), interval: 0.1, holdFor: 0.0);

        $startTime = microtime(true);
        $itemsRead = iterator_count($call($client));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(0, $itemsRead);
        $this->assertGreaterThan(self::HEARTBEAT_TIMEOUT * 3, $processTime);
        $this->assertSame('ended', $this->readServerReport());
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testDeliversItemsThatArriveWithinTheTimeout(Closure $call, string $itemLine): void
    {
        $client = $this->startClient([self::HEARTBEAT_LINE, $itemLine, self::HEARTBEAT_LINE, $itemLine], interval: 0.1, holdFor: 0.0);

        $itemsRead = iterator_count($call($client));

        $this->assertSame(2, $itemsRead);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithoutAHeartbeatTimeoutIfAborted(Closure $call): void
    {
        $client = $this->startClient([self::HEARTBEAT_LINE], interval: 0.0, holdFor: 5.0);

        $client->abortIn(0.2);

        $itemsRead = iterator_count($call($client));

        $this->assertSame(0, $itemsRead);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithoutAHeartbeatTimeoutIfTheLoopIsLeft(Closure $call, string $itemLine): void
    {
        $client = $this->startClient([$itemLine], interval: 0.0, holdFor: 5.0);

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $this->assertSame(1, $itemsRead);
    }

    #[DataProvider('callsWithoutHeartbeats')]
    public function testDoesNotApplyTheHeartbeatTimeoutToStreamsWithoutHeartbeats(Closure $call): void
    {
        $client = $this->startClient([], interval: 0.0, holdFor: self::HEARTBEAT_TIMEOUT * 3);

        $itemsRead = iterator_count($call($client));

        $this->assertSame(0, $itemsRead);
        $this->assertSame('ended', $this->readServerReport());
    }

    private function startClient(array $lines, float $interval, float $holdFor): Client
    {
        $address = $this->startServer($lines, $interval, $holdFor);
        $client = new Client("http://{$address}", 'secret');

        $httpClient = $this->getPropertyValue($client, 'httpClient');
        $curlMultiHandler = $this->getPropertyValue($httpClient, 'curlMultiHandler');
        $this->setPropertyValue($curlMultiHandler, 'heartbeatTimeout', self::HEARTBEAT_TIMEOUT);

        return $client;
    }
}
