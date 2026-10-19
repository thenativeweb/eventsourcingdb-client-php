<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class StallTimeoutTest extends TestCase
{
    use ReflectionTestTrait;
    use ServerTestTrait;

    private const STALL_TIMEOUT = 0.5;
    private const STALL_MESSAGE = 'No data arrived from the server for 0.5 seconds.';

    /**
     * Every call, for every API, with whether it is a write, whose stall
     * leaves its outcome open.
     */
    public static function calls(): Iterator
    {
        foreach (['v1', 'v2'] as $apiVersion) {
            foreach (self::allCalls() as $name => [$call, $isWrite]) {
                yield "{$name} ({$apiVersion})" => [$apiVersion, $call, $isWrite];
            }
        }
    }

    /**
     * The calls that read the response, which registering an event schema
     * through the v1 API does not do.
     */
    public static function callsThatReadTheResponse(): Iterator
    {
        foreach (self::calls() as $name => [$apiVersion, $call, $isWrite]) {
            if ($name === 'registerEventSchema (v1)') {
                continue;
            }

            yield $name => [$apiVersion, $call, $isWrite];
        }
    }

    public static function invalidStallTimeouts(): Iterator
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
    }

    public function testUsesAStallTimeoutOfThirtySecondsByDefault(): void
    {
        $client = new Client('http://localhost:3000', 'secret');

        $httpClient = $this->getPropertyValue($client, 'httpClient');
        $curlMultiHandler = $this->getPropertyValue($httpClient, 'curlMultiHandler');

        $this->assertEqualsWithDelta(30.0, $this->getPropertyValue($curlMultiHandler, 'stallTimeout'), PHP_FLOAT_EPSILON);
    }

    #[DataProvider('invalidStallTimeouts')]
    public function testThrowsIfTheStallTimeoutIsNotAPositiveNumber(float $stallTimeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client('http://localhost:3000', 'secret', stallTimeout: $stallTimeout);
    }

    #[DataProvider('calls')]
    public function testFailsIfTheHeadersDoNotArriveWithinTheStallTimeout(string $apiVersion, Closure $call, bool $isWrite): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([], interval: 0.0, holdFor: 0.0, delay: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertStalls($call, $client, $isWrite);
    }

    #[DataProvider('callsThatReadTheResponse')]
    public function testFailsIfTheBodyDoesNotArriveWithinTheStallTimeout(string $apiVersion, Closure $call, bool $isWrite): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertStalls($call, $client, $isWrite);
    }

    public function testFailsIfTheServerDoesNotAnswerWhichApiItOffersWithinTheStallTimeout(): void
    {
        $address = $this->startServerWithResponses([
            $this->response([], interval: 0.0, holdFor: 0.0, delay: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        // The write was not sent, since the client did not know yet which API
        // to send it with, so its outcome is known.
        $this->assertStalls(
            static function (Client $client): void {
                $client->writeEvents([self::eventCandidate()]);
            },
            $client,
            isWrite: false,
        );
    }

    public function testFailsIfConnectingDoesNotFinishWithinTheStallTimeout(): void
    {
        // The server does not answer the TLS handshake, so connecting never
        // finishes, which cURL would wait for 300 seconds by default.
        $address = $this->startServer([], interval: 0.0, holdFor: 0.0);
        $client = new Client("https://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertStalls(
            static function (Client $client): void {
                $client->ping();
            },
            $client,
            isWrite: false,
        );
    }

    public function testKeepsARequestThatReceivesSomethingWithinEveryStallTimeout(): void
    {
        $lines = array_map(
            static fn (int $index): string => self::item('v1', 'subject', [
                'subject' => "/test/{$index}",
            ]),
            range(1, 6),
        );
        $address = $this->startDatabase('v1', [
            $this->response($lines, interval: 0.3, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $startTime = microtime(true);
        $subjectsRead = iterator_count($client->readSubjects('/', new ReadSubjectsOptions(recursive: true)));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(6, $subjectsRead);
        $this->assertGreaterThan(self::STALL_TIMEOUT * 3, $processTime);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testCountsEveryPartOfTheHeadersAsSomethingThatArrived(): void
    {
        // Together, the delays before the status line and before the other
        // headers last longer than the stall timeout, but each is shorter.
        $address = $this->startDatabase('v1', [
            $this->response([self::item('v1', 'subject', [
                'subject' => '/test',
            ])], interval: 0.0, holdFor: 0.0, delay: 0.3, headerDelay: 0.3),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $subjectsRead = iterator_count($client->readSubjects('/', new ReadSubjectsOptions(recursive: true)));

        $this->assertSame(1, $subjectsRead);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testFailsWithAnExceptionThatExistingCallersCatchAsARuntimeException(): void
    {
        $this->assertTrue(is_a(ConnectionStalledException::class, RuntimeException::class, true));
    }

    private static function allCalls(): Iterator
    {
        yield 'ping' => [
            static function (Client $client): void {
                $client->ping();
            },
            false,
        ];
        yield 'verifyApiToken' => [
            static function (Client $client): void {
                $client->verifyApiToken();
            },
            false,
        ];
        yield 'writeEvents' => [
            static function (Client $client): void {
                $client->writeEvents([self::eventCandidate()]);
            },
            true,
        ];
        yield 'registerEventSchema' => [
            static function (Client $client): void {
                $client->registerEventSchema('io.eventsourcingdb.test', [
                    'type' => 'object',
                ]);
            },
            true,
        ];
        yield 'readEventType' => [
            static function (Client $client): void {
                $client->readEventType('io.eventsourcingdb.test');
            },
            false,
        ];
        yield 'readEvents' => [
            static function (Client $client): void {
                iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
            },
            false,
        ];
        yield 'readSubjects' => [
            static function (Client $client): void {
                iterator_count($client->readSubjects('/', new ReadSubjectsOptions(recursive: true)));
            },
            false,
        ];
        yield 'readEventTypes' => [
            static function (Client $client): void {
                iterator_count($client->readEventTypes());
            },
            false,
        ];
        yield 'observeEvents' => [
            static function (Client $client): void {
                iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true)));
            },
            false,
        ];
        yield 'runEventQlQuery' => [
            static function (Client $client): void {
                iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e'));
            },
            false,
        ];
    }

    private static function eventCandidate(): EventCandidate
    {
        return new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );
    }

    /**
     * Asserts that the call stalls, with a ConnectionStalledException, which
     * for a write that was sent comes wrapped in an OutcomeUnknownException,
     * since the server may have stored the write before it stalled.
     */
    private function assertStalls(Closure $call, Client $client, bool $isWrite): void
    {
        $startTime = microtime(true);
        try {
            $call($client);
            $this->fail('Expected the request to stall, but it did not.');
        } catch (RuntimeException $runtimeException) {
            if ($isWrite) {
                $this->assertInstanceOf(OutcomeUnknownException::class, $runtimeException, $runtimeException->getMessage());
                $runtimeException = $runtimeException->getPrevious();
            }

            $this->assertInstanceOf(ConnectionStalledException::class, $runtimeException, (string) $runtimeException?->getMessage());
            $this->assertSame(self::STALL_MESSAGE, $runtimeException->getMessage());
        }

        $processTime = microtime(true) - $startTime;

        $this->assertGreaterThanOrEqual(self::STALL_TIMEOUT, $processTime);
        $this->assertLessThan(self::STALL_TIMEOUT + 1.0, $processTime);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }
}
