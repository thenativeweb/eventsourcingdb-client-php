<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class HeartbeatTest extends TestCase
{
    use ServerTestTrait;

    private const STALL_TIMEOUT = 0.5;
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

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithAStalledConnectionIfNeitherAnEventNorAHeartbeatArrives(Closure $call): void
    {
        $client = $this->startClient([self::HEARTBEAT_LINE], interval: 0.0, holdFor: 5.0);

        $startTime = microtime(true);
        try {
            iterator_count($call($client));
            $this->fail('Expected the stream to end with a stalled connection, but it ended without one.');
        } catch (ConnectionStalledException $connectionStalledException) {
            $this->assertSame('No data arrived from the server for 0.5 seconds.', $connectionStalledException->getMessage());
        }

        $processTime = microtime(true) - $startTime;

        $this->assertGreaterThanOrEqual(self::STALL_TIMEOUT, $processTime);
        $this->assertLessThan(self::STALL_TIMEOUT + 1.0, $processTime);
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
        $this->assertGreaterThan(self::STALL_TIMEOUT * 3, $processTime);
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
    public function testEndsWithoutAStalledConnectionIfAborted(Closure $call): void
    {
        $client = $this->startClient([self::HEARTBEAT_LINE], interval: 0.0, holdFor: 5.0);

        $client->abortIn(0.2);

        $itemsRead = iterator_count($call($client));

        $this->assertSame(0, $itemsRead);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithoutAStalledConnectionIfTheLoopIsLeft(Closure $call, string $itemLine): void
    {
        $client = $this->startClient([$itemLine], interval: 0.0, holdFor: 5.0);

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $this->assertSame(1, $itemsRead);
    }

    private function startClient(array $lines, float $interval, float $holdFor): Client
    {
        $address = $this->startServer($lines, $interval, $holdFor);

        // The stall timeout sets after how long a stream with heartbeats ends
        // with a stalled connection.
        return new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);
    }
}
