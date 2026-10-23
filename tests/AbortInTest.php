<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;
use Throwable;

/**
 * The time that abortIn sets counts from the call, and bounds the whole
 * request, from asking the server which API it offers and connecting to
 * waiting for the answer and reading it.
 *
 * The server of these tests waits far longer than the stall timeout of the
 * client before it answers, and the stall timeout is far longer than the abort
 * time. A client that does not abort a request in time thus fails it with a
 * stalled connection, rather than with the abort, so these tests tell the two
 * apart by the type of the failure, not by how long it took.
 */
final class AbortInTest extends TestCase
{
    use ServerTestTrait;

    private const ABORT_TIME = 0.2;
    private const STALL_TIMEOUT = 2.0;
    private const SERVER_DELAY = 10.0;

    /**
     * Every call of the client, with what it is: a stream, a write, or a
     * request with a single answer.
     */
    public static function callsOfEveryApiVersion(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            foreach (self::callsWithKind() as $name => [$call, $kind]) {
                yield "{$name} ({$apiVersion})" => [$apiVersion, $call, $kind];
            }
        }
    }

    /**
     * Every call of the client, with what it is, for a client that does not
     * know yet which API the server offers.
     */
    public static function calls(): Iterator
    {
        yield from self::callsWithKind();
    }

    /**
     * The calls whose answer is a single item, rather than a stream.
     */
    public static function singleAnswerCallsOfEveryApiVersion(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            foreach (self::callsWithKind() as $name => [$call, $kind]) {
                if ($kind !== 'answer') {
                    continue;
                }

                yield "{$name} ({$apiVersion})" => [$apiVersion, $call];
            }
        }
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     * @param 'stream'|'write'|'answer' $kind
     */
    #[DataProvider('callsOfEveryApiVersion')]
    public function testAbortsARequestWhoseServerHasNotBegunToAnswer(string $apiVersion, Closure $call, string $kind): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->pingResponse($apiVersion),
            $this->response([], interval: 0.0, holdFor: 0.0, delay: self::SERVER_DELAY),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        // The client finds out which API the server offers first, so that the
        // abort time meets the request itself.
        $client->ping();
        $this->assertSame('ended', $this->readServerReport());

        $client->abortIn(self::ABORT_TIME);

        $this->assertIsAborted($call, $client, $kind);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');

        // The server received the request before it began to wait, so a write
        // that is aborted while it waits has been sent.
        $this->assertCount(3, $this->readRequests(), 'Expected the request to have been sent.');
    }

    /**
     * @param 'stream'|'write'|'answer' $kind
     */
    #[DataProvider('calls')]
    public function testAbortsAskingTheServerWhichApiItOffers(Closure $call, string $kind): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 0.0, delay: self::SERVER_DELAY),
            $this->probeResponse('v2'),
            $this->pingResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->abortIn(self::ABORT_TIME);

        // Asking failed before the request was sent, so a write that is
        // aborted while the client asks was certainly not stored.
        $this->assertIsAborted($call, $client, $kind, hasBeenSent: false);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
        $this->assertCount(1, $this->readRequests(), 'Expected only the request that asks to be sent.');

        // Asking used up the abort time, so the next request is not aborted,
        // and asks again.
        $client->ping();

        $this->assertSame('ended', $this->readServerReport());
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testDoesNotAskTheServerWhichApiItOffersOnceTheAbortTimeIsOver(): void
    {
        $address = $this->startServerWithResponses([
            $this->probeResponse('v2'),
            $this->pingResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->abortIn(self::ABORT_TIME);
        usleep((int) (self::ABORT_TIME * 1.5 * 1_000_000));

        $this->assertIsAborted(static function (Client $client): void {
            $client->ping();
        }, $client, 'answer');
        $this->assertSame([], $this->readRequests(), 'Expected no request to be sent.');
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('singleAnswerCallsOfEveryApiVersion')]
    public function testAbortsASingleAnswerWhileItIsRead(string $apiVersion, Closure $call): void
    {
        // The server sends the headers of the answer, but not its body.
        $address = $this->startDatabase($apiVersion, [
            $this->pingResponse($apiVersion),
            $this->response([], interval: 0.0, holdFor: self::SERVER_DELAY, contentType: 'application/json'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->ping();
        $this->assertSame('ended', $this->readServerReport());

        $client->abortIn(self::ABORT_TIME);

        $this->assertIsAborted($call, $client, 'answer');
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testAbortsAskingTheServerWhichApiItOffersWhileItsAnswerIsRead(): void
    {
        // The server sends the headers of its answer, but not its body.
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: self::SERVER_DELAY, contentType: 'application/json'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->abortIn(self::ABORT_TIME);

        $this->assertIsAborted(static function (Client $client): void {
            $client->ping();
        }, $client, 'answer');
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testLeavesTheAbortTimeToTheRequestOnceTheServerHasToldItsApi(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([], interval: 0.0, holdFor: 0.0, delay: self::SERVER_DELAY),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        // Asking the server which API it offers is the first request of the
        // client, and does not take the abort time over.
        $client->abortIn(self::ABORT_TIME);

        $this->assertIsAborted(static function (Client $client): void {
            $client->ping();
        }, $client, 'answer');
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testDoesNotSendARequestWhoseAbortTimeIsOver(): void
    {
        $address = $this->startDatabase('v2', [
            $this->pingResponse('v2'),
            $this->pingResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->ping();

        // The abort time counts from the call, so it is over before the
        // request starts.
        $client->abortIn(self::ABORT_TIME);
        usleep((int) (self::ABORT_TIME * 1.5 * 1_000_000));

        $this->assertIsAborted(static function (Client $client): void {
            $client->ping();
        }, $client, 'answer');
        $this->assertCount(2, $this->readRequests(), 'Expected the request not to be sent.');
    }

    public function testEndsAStreamWhoseAbortTimeIsOverWithoutSendingIt(): void
    {
        $address = $this->startDatabase('v2', [
            $this->pingResponse('v2'),
            $this->response([self::eventItem('v2'), self::item('v2', 'end')], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->ping();

        $client->abortIn(self::ABORT_TIME);

        $events = $client->readEvents('/', new ReadEventsOptions(recursive: true));
        usleep((int) (self::ABORT_TIME * 1.5 * 1_000_000));

        $eventsRead = iterator_count($events);

        $this->assertSame(0, $eventsRead);
        $this->assertCount(2, $this->readRequests(), 'Expected the request not to be sent.');
    }

    public function testEndsAStreamWhoseHeadersArriveLateAtTheAbortTimeCountedFromTheCall(): void
    {
        // The server sends the headers of the stream shortly before the abort
        // time is over, and then nothing. Counted from the call, the abort
        // time is over shortly after reading begins, rather than a whole abort
        // time later.
        $abortTime = 1.0;
        $address = $this->startDatabase('v2', [
            $this->pingResponse('v2'),
            $this->response([], interval: 0.0, holdFor: self::SERVER_DELAY, delay: $abortTime * 0.8),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $client->ping();
        $this->assertSame('ended', $this->readServerReport());

        $startTime = microtime(true);
        $client->abortIn($abortTime);

        $eventsRead = iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(0, $eventsRead);
        $this->assertGreaterThanOrEqual($abortTime, $processTime);
        $this->assertLessThan($abortTime * 1.4, $processTime, "Expected the stream to end at the abort time counted from the call, but it took {$processTime} seconds.");
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public function testDoesNotAbortARequestMadeWithinTheLoopWhileItWaitsForItsAnswer(): void
    {
        // The server answers one request at a time, so it answers the ping
        // made with the first event only once it has ended the stream, long
        // after the abort time that the client sets with the first event is
        // over.
        $address = $this->startDatabase('v2', [
            $this->response([self::eventItem('v2'), self::eventItem('v2'), self::item('v2', 'end')], interval: 0.3, holdFor: 0.0),
            $this->pingResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $eventsRead = 0;
        foreach ($client->readEvents('/', new ReadEventsOptions(recursive: true)) as $event) {
            ++$eventsRead;

            if ($eventsRead > 1) {
                continue;
            }

            $client->abortIn(0.1);

            // The ping does not take over the abort time of the stream, so it
            // gets its answer, and the stream ends at the abort time.
            $client->ping();
        }

        $this->assertSame(1, $eventsRead, 'Expected the stream to be aborted at the time that abortIn set within the loop.');
    }

    /**
     * @return iterable<string, array{Closure(Client): mixed, 'stream'|'write'|'answer'}>
     */
    private static function callsWithKind(): iterable
    {
        yield 'ping' => [
            static function (Client $client): void {
                $client->ping();
            },
            'answer',
        ];
        yield 'verifyApiToken' => [
            static function (Client $client): void {
                $client->verifyApiToken();
            },
            'answer',
        ];
        yield 'readEventType' => [
            static fn (Client $client): mixed => $client->readEventType('io.eventsourcingdb.test'),
            'answer',
        ];
        yield 'writeEvents' => [
            static fn (Client $client): array => $client->writeEvents([]),
            'write',
        ];
        yield 'registerEventSchema' => [
            static function (Client $client): void {
                $client->registerEventSchema('io.eventsourcingdb.test', [
                    'type' => 'object',
                ]);
            },
            'write',
        ];
        yield 'readEvents' => [
            static fn (Client $client): int => iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true))),
            'stream',
        ];
        yield 'runEventQlQuery' => [
            static fn (Client $client): int => iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e')),
            'stream',
        ];
        yield 'observeEvents' => [
            static fn (Client $client): int => iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true))),
            'stream',
        ];
        yield 'readSubjects' => [
            static fn (Client $client): int => iterator_count($client->readSubjects('/', new ReadSubjectsOptions(recursive: true))),
            'stream',
        ];
        yield 'readEventTypes' => [
            static fn (Client $client): int => iterator_count($client->readEventTypes()),
            'stream',
        ];
    }

    /**
     * The answer to a ping, in the shape of the given API.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private function pingResponse(string $apiVersion): array
    {
        if ($apiVersion === 'v2') {
            return $this->response([self::$pingOfV2], interval: 0.0, holdFor: 0.0, contentType: 'application/json');
        }

        return $this->response(['{"type":"io.eventsourcingdb.api.ping-received"}'], interval: 0.0, holdFor: 0.0, contentType: 'application/json');
    }

    /**
     * Asserts that the call ends the way an aborted request of its kind ends:
     * a stream without an exception and without items, a write that has been
     * sent with an OutcomeUnknownException whose previous exception is the
     * abort, and any other request with the abort itself.
     *
     * @param 'stream'|'write'|'answer' $kind
     * @param bool $hasBeenSent whether the request has been sent before it is aborted, which leaves the outcome of a write open
     */
    private function assertIsAborted(Closure $call, Client $client, string $kind, bool $hasBeenSent = true): void
    {
        try {
            $itemsRead = $call($client);
        } catch (Throwable $throwable) {
            $description = sprintf('Expected the request to be aborted, but it failed with %s: %s', $throwable::class, $throwable->getMessage());

            $this->assertNotSame('stream', $kind, $description);

            if ($kind === 'write' && $hasBeenSent) {
                $this->assertInstanceOf(OutcomeUnknownException::class, $throwable, $description);
                $throwable = $throwable->getPrevious();
            }

            $this->assertInstanceOf(RequestAbortedException::class, $throwable, $description);
            $this->assertSame('The request was aborted, since the time that abortIn set was over.', $throwable->getMessage());

            return;
        }

        $this->assertSame('stream', $kind, 'Expected the request to be aborted, but it succeeded.');
        $this->assertSame(0, $itemsRead);
    }
}
