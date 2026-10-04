<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class ReadingStreamsTest extends TestCase
{
    use ServerTestTrait;

    public static function streamingCalls(): Iterator
    {
        $eventLine = self::eventLine();

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

    public static function calls(): Iterator
    {
        yield 'ping' => [
            static function (Client $client): void {
                $client->ping();
            },
        ];
        yield 'verifyApiToken' => [
            static function (Client $client): void {
                $client->verifyApiToken();
            },
        ];
        yield 'writeEvents' => [
            static function (Client $client): void {
                $client->writeEvents([]);
            },
        ];
        yield 'readEvents' => [
            static function (Client $client): void {
                iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
            },
        ];
        yield 'runEventQlQuery' => [
            static function (Client $client): void {
                iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e'));
            },
        ];
        yield 'observeEvents' => [
            static function (Client $client): void {
                iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true)));
            },
        ];
        yield 'registerEventSchema' => [
            static function (Client $client): void {
                $client->registerEventSchema('io.eventsourcingdb.test', [
                    'type' => 'object',
                ]);
            },
        ];
        yield 'readSubjects' => [
            static function (Client $client): void {
                iterator_count($client->readSubjects('/'));
            },
        ];
        yield 'readEventTypes' => [
            static function (Client $client): void {
                iterator_count($client->readEventTypes());
            },
        ];
        yield 'readEventType' => [
            static function (Client $client): void {
                $client->readEventType('io.eventsourcingdb.test');
            },
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

    public function testClosesTheConnectionOfAResponseThatIsNeverRead(): void
    {
        $address = $this->startServer([], interval: 0.0, holdFor: 5.0);
        $client = new Client("http://{$address}", 'secret');

        // Registering an event schema does not read the response.
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('calls')]
    public function testClosesTheConnectionIfTheServerIsNotEventSourcingDb(Closure $call): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 5.0, server: 'nginx'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $call($client);
            $this->fail('Expected the request to be refused, but it was not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Server must be EventSourcingDB.', $runtimeException->getMessage());
        }

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testReadsAStreamWhoseHeadersArriveInSeveralPackets(): void
    {
        // The server sends the status line first and the other headers a
        // little later, so that they arrive in separate packets.
        $address = $this->startServerWithResponses([
            $this->response([self::eventLine()], interval: 0.0, holdFor: 0.0, headerDelay: 0.2),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsRead = iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame(1, $eventsRead);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testDoesNotApplyAbortInToALaterRequest(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 2.0),
            $this->response([], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $client->abortIn(0.2);
        iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame('closed', $this->readServerReport(), 'Expected the first request to be aborted.');

        iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame('ended', $this->readServerReport(), 'Expected the second request not to be aborted.');
    }

    public function testDoesNotApplyAbortInToALaterRequestIfTheLoopWasLeft(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([self::eventLine()], interval: 0.0, holdFor: 2.0),
            $this->response([self::eventLine()], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $events = $client->readEvents('/', new ReadEventsOptions(recursive: true));
        foreach ($events as $event) {
            $client->abortIn(0.2);

            break;
        }

        $eventsRead = iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        // The first request stays open as long as its iterator is kept, so
        // the server ends it once the hold time is over.
        $this->assertSame('ended', $this->readServerReport());
        $this->assertSame(1, $eventsRead);
        $this->assertSame('ended', $this->readServerReport(), 'Expected the second request not to be aborted.');
    }

    public function testDoesNotApplyAbortInToARequestMadeWithinTheLoop(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([self::eventLine(), self::eventLine()], interval: 0.0, holdFor: 0.0),
            $this->response([], interval: 0.0, holdFor: 1.5),
            $this->response([], interval: 0.0, holdFor: 1.5),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsRead = 0;
        foreach ($client->readEvents('/', new ReadEventsOptions(recursive: true)) as $event) {
            ++$eventsRead;

            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

            $client->abortIn(0.2);
        }

        $this->assertSame(2, $eventsRead);
        $this->assertSame('ended', $this->readServerReport());
        $this->assertSame('ended', $this->readServerReport());
        $this->assertSame('ended', $this->readServerReport(), 'Expected the request made within the loop not to be aborted.');
    }

    public function testAppliesAbortInToTheNextRequestIfNoStreamIsBeingRead(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 2.0),
            $this->response([], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        // Registering an event schema does not read the response, so its
        // request is not a stream being read when abortIn is called.
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);

        $client->abortIn(0.2);
        iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame('closed', $this->readServerReport());
        $this->assertSame('closed', $this->readServerReport(), 'Expected the next request to be aborted.');
    }

    private static function eventLine(): string
    {
        return json_encode([
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
    }
}
