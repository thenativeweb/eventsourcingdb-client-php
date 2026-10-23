<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\IncompatibleServerException;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\PreconditionOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class ReadingStreamsTest extends TestCase
{
    use ServerTestTrait;

    public static function apiVersions(): Iterator
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    public static function streamingCalls(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            foreach (self::streamingCallsOf($apiVersion) as $name => [$call, $itemLine]) {
                yield "{$name} ({$apiVersion})" => [$apiVersion, $call, $itemLine];
            }
        }
    }

    /**
     * The calls whose answer is a stream that ends with an item of type end in
     * the v2 API.
     */
    public static function finiteStreamingCalls(): Iterator
    {
        foreach (self::streamingCallsOf('v2') as $name => [$call, $itemLine]) {
            if ($name === 'observeEvents') {
                continue;
            }

            yield $name => [$call, $itemLine];
        }
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
                iterator_count($client->readSubjects('/', new ReadSubjectsOptions(recursive: true)));
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

    public static function callsOfEveryApiVersion(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            foreach (self::calls() as $name => [$call]) {
                yield "{$name} ({$apiVersion})" => [$apiVersion, $call, in_array($name, ['writeEvents', 'registerEventSchema'], true)];
            }
        }
    }

    #[DataProvider('streamingCalls')]
    public function testHandsOverAnItemAsSoonAsItHasArrived(string $apiVersion, Closure $call, string $itemLine): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([$itemLine], interval: 0.0, holdFor: 5.0),
        ]);
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
    public function testClosesTheConnectionIfTheLoopIsLeft(string $apiVersion, Closure $call, string $itemLine): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([$itemLine], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $itemsRead = 0;
        foreach ($call($client) as $item) {
            ++$itemsRead;

            break;
        }

        $this->assertSame(1, $itemsRead);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('finiteStreamingCalls')]
    public function testReadsAStreamToItsEndItem(Closure $call, string $itemLine): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([$itemLine, self::item('v2', 'heartbeat'), $itemLine, self::item('v2', 'end')], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $itemsRead = iterator_count($call($client));

        $this->assertSame(2, $itemsRead);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed after the end item.');
    }

    #[DataProvider('finiteStreamingCalls')]
    public function testFailsIfAStreamEndsBeforeItsEndItem(Closure $call, string $itemLine): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([$itemLine], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $itemsRead = 0;
        try {
            foreach ($call($client) as $item) {
                ++$itemsRead;
            }

            $this->fail('Expected the stream to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringEndsWith(', since the stream broke off.', $runtimeException->getMessage());
        }

        $this->assertSame(1, $itemsRead);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testFailsIfTheServerEndsAnObservationWithoutAnError(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([self::eventItem($apiVersion)], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsObserved = 0;
        try {
            foreach ($client->observeEvents('/', new ObserveEventsOptions(recursive: true)) as $event) {
                ++$eventsObserved;
            }

            $this->fail('Expected the observation to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertNotInstanceOf(DbApiException::class, $runtimeException);
            $this->assertSame('Failed to observe events, since the stream broke off.', $runtimeException->getMessage());
        }

        $this->assertSame(1, $eventsObserved);
    }

    public function testFailsWithTheErrorWithWhichTheServerEndsAnObservation(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([
                self::eventItem('v2'),
                self::item('v2', 'error', [
                    'code' => ErrorCode::SERVER_SHUTTING_DOWN,
                    'description' => 'server is shutting down',
                    'details' => (object) [],
                ]),
            ], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsObserved = 0;
        try {
            foreach ($client->observeEvents('/', new ObserveEventsOptions(recursive: true)) as $event) {
                ++$eventsObserved;
            }

            $this->fail('Expected the observation to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(ErrorCode::SERVER_SHUTTING_DOWN, $dbApiException->errorCode);
        }

        $this->assertSame(1, $eventsObserved);
    }

    public function testClosesTheConnectionOfAResponseThatIsNeverRead(): void
    {
        $address = $this->startDatabase('v1', [
            $this->response([], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        // Registering an event schema through the v1 API does not read the
        // response.
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('calls')]
    public function testClosesTheConnectionIfTheServerThatIsAskedForItsApiIsNotEventSourcingDb(Closure $call): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 5.0, server: 'nginx'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $call($client);
            $this->fail('Expected the request to be refused, but it was not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $runtimeException, 'Expected a write that was not sent not to have an unknown outcome.');
            $this->assertSame('Server must be EventSourcingDB.', $runtimeException->getMessage());
        }

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('callsOfEveryApiVersion')]
    public function testClosesTheConnectionIfTheServerIsNotEventSourcingDb(string $apiVersion, Closure $call, bool $isWrite): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([], interval: 0.0, holdFor: 5.0, server: 'nginx'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $call($client);
            $this->fail('Expected the request to be refused, but it was not.');
        } catch (RuntimeException $runtimeException) {
            // A write that an answer from another server meets may have reached
            // EventSourcingDB nonetheless, e.g. through a proxy.
            if ($isWrite) {
                $this->assertInstanceOf(OutcomeUnknownException::class, $runtimeException);
                $runtimeException = $runtimeException->getPrevious();
                $this->assertInstanceOf(RuntimeException::class, $runtimeException);
            }

            $this->assertSame('Server must be EventSourcingDB.', $runtimeException->getMessage());
        }

        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    #[DataProvider('apiVersions')]
    public function testReadsAStreamWhoseHeadersArriveInSeveralPackets(string $apiVersion): void
    {
        // The server sends the status line first and the other headers a
        // little later, so that they arrive in separate packets.
        $address = $this->startDatabase($apiVersion, [
            $this->response(
                $apiVersion === 'v2' ? [self::eventItem($apiVersion), self::item($apiVersion, 'end')] : [self::eventItem($apiVersion)],
                interval: 0.0,
                holdFor: 0.0,
                headerDelay: 0.2,
            ),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsRead = iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame(1, $eventsRead);
    }

    #[DataProvider('streamingCalls')]
    public function testStopsAStreamAtTheTimeAbortInSets(string $apiVersion, Closure $call): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        // The abort time counts from the call, so the time is taken before it.
        $startTime = microtime(true);
        $client->abortIn(0.2);

        $itemsRead = iterator_count($call($client));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(0, $itemsRead);
        $this->assertGreaterThanOrEqual(0.2, $processTime);
        $this->assertLessThan(0.5, $processTime, "Expected the stream to be stopped after 0.2 seconds, but it took {$processTime} seconds.");
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testDoesNotApplyAbortInToALaterRequest(): void
    {
        $address = $this->startDatabase('v1', [
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
        $address = $this->startDatabase('v1', [
            $this->response([self::eventItem('v1')], interval: 0.0, holdFor: 2.0),
            $this->response([self::eventItem('v1')], interval: 0.0, holdFor: 2.0),
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
        $address = $this->startDatabase('v1', [
            $this->response([self::eventItem('v1'), self::eventItem('v1')], interval: 0.0, holdFor: 0.0),
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

    public function testUsesUpTheAbortTimeOfARequestThatFailsWhileAskingWhichApiTheServerOffers(): void
    {
        $address = $this->startServerWithResponses([
            $this->textResponse(503, 'server is shutting down'),
            $this->probeResponse('v2'),
            $this->response([], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $client->abortIn(1.0);

        try {
            $client->ping();
            $this->fail('Expected the ping to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(503, $dbApiException->status);
        }

        // The ping used up the abort time, so the observation is not aborted,
        // and breaks off once the server ends it.
        try {
            iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true)));
            $this->fail('Expected the observation to break off, but it ended quietly.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Failed to observe events, since the stream broke off.', $runtimeException->getMessage());
        }
    }

    public function testUsesUpTheAbortTimeOfAWriteThatTheClientRefusesBeforeSendingIt(): void
    {
        $address = $this->startDatabase('v1', [
            $this->response([], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $client->abortIn(1.0);

        try {
            $this->writeWithRecursivePrecondition($client);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (IncompatibleServerException) {
        }

        // The write used up the abort time, so the observation is not aborted,
        // and breaks off once the server ends it.
        try {
            iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true)));
            $this->fail('Expected the observation to break off, but it ended quietly.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Failed to observe events, since the stream broke off.', $runtimeException->getMessage());
        }
    }

    public static function outcomesOfAskingAgain(): Iterator
    {
        yield 'the server still offers the v1 API only' => [false, IncompatibleServerException::class];
        yield 'asking fails' => [true, DbApiException::class];
    }

    /**
     * @param class-string<RuntimeException> $expectedException
     */
    #[DataProvider('outcomesOfAskingAgain')]
    public function testKeepsTheAbortTimeOfTheStreamBeingReadIfAWriteMadeWithinTheLoopFailsBeforeItIsSent(bool $isAskingFailing, string $expectedException): void
    {
        // The server answers one request at a time, so it answers the request
        // that asks again which API it offers only once it has sent the second
        // event, long after the abort time that the client sets with the first
        // event is over. A client that keeps the abort time thus stops reading
        // before the second event.
        $address = $this->startDatabase('v1', [
            $this->response([self::eventItem('v1'), self::eventItem('v1')], interval: 0.5, holdFor: 0.0),
            $isAskingFailing ? $this->textResponse(503, 'server is shutting down') : $this->probeResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $eventsRead = 0;
        foreach ($client->readEvents('/', new ReadEventsOptions(recursive: true)) as $event) {
            ++$eventsRead;

            // The server answers no further request, so the client writes
            // only once, with the first event.
            if ($eventsRead > 1) {
                continue;
            }

            $client->abortIn(0.1);

            try {
                $this->writeWithRecursivePrecondition($client);
                $this->fail('Expected the write to fail, but it did not.');
            } catch (RuntimeException $runtimeException) {
                $this->assertInstanceOf($expectedException, $runtimeException, $runtimeException->getMessage());
            }
        }

        $this->assertSame(1, $eventsRead, 'Expected the stream to be aborted at the time that abortIn set within the loop.');
    }

    public function testAppliesAbortInToTheNextRequestIfNoStreamIsBeingRead(): void
    {
        $address = $this->startDatabase('v1', [
            $this->response([], interval: 0.0, holdFor: 2.0),
            $this->response([], interval: 0.0, holdFor: 2.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        // Registering an event schema through the v1 API does not read the
        // response, so its request is not a stream being read when abortIn is
        // called.
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);

        $client->abortIn(0.2);
        iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));

        $this->assertSame('closed', $this->readServerReport());
        $this->assertSame('closed', $this->readServerReport(), 'Expected the next request to be aborted.');
    }

    private function writeWithRecursivePrecondition(Client $client): void
    {
        $client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
        ], [
            new IsSubjectPristine('/test', new PreconditionOptions(recursive: true)),
        ]);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    private static function streamingCallsOf(string $apiVersion): Iterator
    {
        yield 'readEvents' => [
            static fn (Client $client): iterable => $client->readEvents('/', new ReadEventsOptions(recursive: true)),
            self::eventItem($apiVersion),
        ];
        yield 'observeEvents' => [
            static fn (Client $client): iterable => $client->observeEvents('/', new ObserveEventsOptions(recursive: true)),
            self::eventItem($apiVersion),
        ];
        yield 'runEventQlQuery' => [
            static fn (Client $client): iterable => $client->runEventQlQuery('FROM e IN events PROJECT INTO e'),
            self::item($apiVersion, 'row', [
                'value' => 23,
            ]),
        ];
        yield 'readSubjects' => [
            static fn (Client $client): iterable => $client->readSubjects('/', new ReadSubjectsOptions(recursive: true)),
            self::item($apiVersion, 'subject', [
                'subject' => '/test',
            ]),
        ];
        yield 'readEventTypes' => [
            static fn (Client $client): iterable => $client->readEventTypes(),
            $apiVersion === 'v2'
                ? self::item($apiVersion, 'eventType', [
                    'type' => 'io.eventsourcingdb.test',
                    'hasEvents' => true,
                    'schema' => null,
                ])
                : self::item($apiVersion, 'eventType', [
                    'eventType' => 'io.eventsourcingdb.test',
                    'isPhantom' => false,
                ]),
        ];
    }
}
