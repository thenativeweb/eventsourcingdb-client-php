<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class HeartbeatTest extends TestCase
{
    use ServerTestTrait;

    private const STALL_TIMEOUT = 0.5;

    public static function callsWithHeartbeats(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            yield "observeEvents ({$apiVersion})" => [
                $apiVersion,
                static fn (Client $client): iterable => $client->observeEvents('/', new ObserveEventsOptions(recursive: true)),
                self::eventItem($apiVersion),
            ];
            yield "runEventQlQuery ({$apiVersion})" => [
                $apiVersion,
                static fn (Client $client): iterable => $client->runEventQlQuery('FROM e IN events PROJECT INTO e'),
                self::item($apiVersion, 'row', [
                    'value' => 23,
                ]),
            ];
        }
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithAStalledConnectionIfNeitherAnItemNorAHeartbeatArrives(string $apiVersion, Closure $call): void
    {
        $client = $this->startClient($apiVersion, [self::item($apiVersion, 'heartbeat')], interval: 0.0, holdFor: 5.0);

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
    public function testKeepsReadingWhileHeartbeatsArrive(string $apiVersion, Closure $call): void
    {
        // The heartbeats last longer than the stream is read, which abortIn
        // ends after three times the stall timeout.
        $client = $this->startClient($apiVersion, array_fill(0, 20, self::item($apiVersion, 'heartbeat')), interval: 0.1, holdFor: 5.0);

        // The abort time counts from the call, so the time is taken before it.
        $startTime = microtime(true);
        $client->abortIn(self::STALL_TIMEOUT * 3);

        $itemsRead = iterator_count($call($client));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(0, $itemsRead);
        $this->assertGreaterThanOrEqual(self::STALL_TIMEOUT * 3, $processTime);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testDeliversItemsThatArriveWithinTheStallTimeout(string $apiVersion, Closure $call, string $itemLine): void
    {
        $heartbeat = self::item($apiVersion, 'heartbeat');
        $client = $this->startClient($apiVersion, [$heartbeat, $itemLine, $heartbeat, $itemLine], interval: 0.1, holdFor: 5.0);

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            if ($itemsRead === 2) {
                break;
            }
        }

        $this->assertSame(2, $itemsRead);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithoutAStalledConnectionIfAborted(string $apiVersion, Closure $call): void
    {
        $client = $this->startClient($apiVersion, [self::item($apiVersion, 'heartbeat')], interval: 0.0, holdFor: 5.0);

        $client->abortIn(0.2);

        $itemsRead = iterator_count($call($client));

        $this->assertSame(0, $itemsRead);
    }

    #[DataProvider('callsWithHeartbeats')]
    public function testEndsWithoutAStalledConnectionIfTheLoopIsLeft(string $apiVersion, Closure $call, string $itemLine): void
    {
        $client = $this->startClient($apiVersion, [$itemLine], interval: 0.0, holdFor: 5.0);

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $this->assertSame(1, $itemsRead);
    }

    public function testKeepsAWriteThatReceivesHeartbeats(): void
    {
        // The v2 API sends heartbeats while a write takes long, e.g. because of
        // the EventQL query of a precondition.
        $heartbeat = self::item('v2', 'heartbeat');
        $client = $this->startClient('v2', [$heartbeat, $heartbeat, $heartbeat, $heartbeat, self::eventItem('v2'), self::item('v2', 'end')], interval: 0.3, holdFor: 0.0);

        $startTime = microtime(true);
        $writtenEvents = $client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
        ]);
        $processTime = microtime(true) - $startTime;

        $this->assertCount(1, $writtenEvents);
        $this->assertGreaterThan(self::STALL_TIMEOUT * 2, $processTime);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    private function startClient(string $apiVersion, array $lines, float $interval, float $holdFor): Client
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response($lines, $interval, $holdFor),
        ]);

        return new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);
    }
}
