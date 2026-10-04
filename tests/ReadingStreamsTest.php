<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class ReadingStreamsTest extends TestCase
{
    use ServerTestTrait;

    public static function streamingCalls(): Iterator
    {
        $eventLine = json_encode([
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
        ], JSON_THROW_ON_ERROR);

        yield 'readEvents' => [
            static fn (Client $client): iterable => $client->readEvents('/', new ReadEventsOptions(recursive: true)),
            $eventLine,
        ];
        yield 'observeEvents' => [
            static fn (Client $client): iterable => $client->observeEvents('/', new ObserveEventsOptions(recursive: true)),
            $eventLine,
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
        yield 'readSubjects' => [
            static fn (Client $client): iterable => $client->readSubjects('/'),
            json_encode([
                'type' => 'subject',
                'payload' => [
                    'subject' => '/test',
                ],
            ], JSON_THROW_ON_ERROR),
        ];
        yield 'readEventTypes' => [
            static fn (Client $client): iterable => $client->readEventTypes(),
            json_encode([
                'type' => 'eventType',
                'payload' => [
                    'eventType' => 'io.eventsourcingdb.test',
                    'isPhantom' => false,
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    #[DataProvider('streamingCalls')]
    public function testHandsOverAnItemAsSoonAsItHasArrived(Closure $call, string $itemLine): void
    {
        $address = $this->startServer([$itemLine], interval: 0.0, holdFor: 5.0);
        $client = new Client("http://{$address}", 'secret');

        $startTime = microtime(true);
        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $processTime = microtime(true) - $startTime;

        $this->assertSame(1, $itemsRead);
        $this->assertLessThan(0.5, $processTime, "Expected the item to be handed over right away, but it took {$processTime} seconds.");
    }

    #[DataProvider('streamingCalls')]
    public function testClosesTheConnectionIfTheLoopIsLeft(Closure $call, string $itemLine): void
    {
        $address = $this->startServer([$itemLine], interval: 0.0, holdFor: 5.0);
        $client = new Client("http://{$address}", 'secret');

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $this->assertSame(1, $itemsRead);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }
}
