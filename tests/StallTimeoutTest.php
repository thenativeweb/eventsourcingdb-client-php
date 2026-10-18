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
use Thenativeweb\Eventsourcingdb\HeartbeatTimeoutException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class StallTimeoutTest extends TestCase
{
    use ReflectionTestTrait;
    use ServerTestTrait;

    private const STALL_TIMEOUT = 0.5;
    private const STALL_MESSAGE = 'No data arrived from the server for 0.5 seconds.';

    public static function calls(): Iterator
    {
        foreach (self::callsThatReadTheResponse() as $name => [$call]) {
            yield $name => [$call];
        }

        yield 'registerEventSchema' => [
            static function (Client $client): void {
                $client->registerEventSchema('io.eventsourcingdb.test', [
                    'type' => 'object',
                ]);
            },
        ];
    }

    public static function callsThatReadTheResponse(): Iterator
    {
        yield 'ping' => [
            static function (Client $client): void {
                $client->ping();
            },
            ConnectionStalledException::class,
        ];
        yield 'verifyApiToken' => [
            static function (Client $client): void {
                $client->verifyApiToken();
            },
            ConnectionStalledException::class,
        ];
        yield 'writeEvents' => [
            static function (Client $client): void {
                $client->writeEvents([]);
            },
            ConnectionStalledException::class,
        ];
        yield 'readEventType' => [
            static function (Client $client): void {
                $client->readEventType('io.eventsourcingdb.test');
            },
            ConnectionStalledException::class,
        ];
        yield 'readEvents' => [
            static function (Client $client): void {
                iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
            },
            ConnectionStalledException::class,
        ];
        yield 'readSubjects' => [
            static function (Client $client): void {
                iterator_count($client->readSubjects('/'));
            },
            ConnectionStalledException::class,
        ];
        yield 'readEventTypes' => [
            static function (Client $client): void {
                iterator_count($client->readEventTypes());
            },
            ConnectionStalledException::class,
        ];

        // Streams with heartbeats ended with a heartbeat timeout before the
        // stall timeout replaced it, and keep doing so.
        yield 'observeEvents' => [
            static function (Client $client): void {
                iterator_count($client->observeEvents('/', new ObserveEventsOptions(recursive: true)));
            },
            HeartbeatTimeoutException::class,
        ];
        yield 'runEventQlQuery' => [
            static function (Client $client): void {
                iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e'));
            },
            HeartbeatTimeoutException::class,
        ];
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
    public function testFailsIfTheHeadersDoNotArriveWithinTheStallTimeout(Closure $call): void
    {
        $address = $this->startServer([], interval: 0.0, holdFor: 0.0, delay: 5.0);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertStalls($call, $client, ConnectionStalledException::class, self::STALL_MESSAGE);
    }

    #[DataProvider('callsThatReadTheResponse')]
    public function testFailsIfTheBodyDoesNotArriveWithinTheStallTimeout(Closure $call, string $exceptionClass): void
    {
        $address = $this->startServer([], interval: 0.0, holdFor: 5.0);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertStalls(
            $call,
            $client,
            $exceptionClass,
            $exceptionClass === HeartbeatTimeoutException::class ? 'No event and no heartbeat arrived for 0.5 seconds.' : self::STALL_MESSAGE,
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
            ConnectionStalledException::class,
            self::STALL_MESSAGE,
        );
    }

    public function testKeepsARequestThatReceivesSomethingWithinEveryStallTimeout(): void
    {
        $lines = array_map(
            static fn (int $index): string => json_encode([
                'type' => 'subject',
                'payload' => [
                    'subject' => "/test/{$index}",
                ],
            ], JSON_THROW_ON_ERROR),
            range(1, 6),
        );
        $address = $this->startServer($lines, interval: 0.3, holdFor: 0.0);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $startTime = microtime(true);
        $subjectsRead = iterator_count($client->readSubjects('/'));
        $processTime = microtime(true) - $startTime;

        $this->assertSame(6, $subjectsRead);
        $this->assertGreaterThan(self::STALL_TIMEOUT * 3, $processTime);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testCountsEveryPartOfTheHeadersAsSomethingThatArrived(): void
    {
        // Together, the delays before the status line and before the other
        // headers last longer than the stall timeout, but each is shorter.
        $address = $this->startServerWithResponses([
            $this->response(['{"type":"subject","payload":{"subject":"/test"}}'], interval: 0.0, holdFor: 0.0, delay: 0.3, headerDelay: 0.3),
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $subjectsRead = iterator_count($client->readSubjects('/'));

        $this->assertSame(1, $subjectsRead);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testFailsWithAnExceptionThatExistingCallersCatchAsARuntimeException(): void
    {
        $this->assertTrue(is_a(ConnectionStalledException::class, RuntimeException::class, true));
    }

    /**
     * @param class-string<RuntimeException> $exceptionClass
     */
    private function assertStalls(Closure $call, Client $client, string $exceptionClass, string $message): void
    {
        $startTime = microtime(true);
        try {
            $call($client);
            $this->fail("Expected the request to fail with a {$exceptionClass}, but it did not fail.");
        } catch (RuntimeException $runtimeException) {
            $this->assertInstanceOf($exceptionClass, $runtimeException, $runtimeException->getMessage());
            $this->assertSame($message, $runtimeException->getMessage());
        }

        $processTime = microtime(true) - $startTime;

        $this->assertGreaterThanOrEqual(self::STALL_TIMEOUT, $processTime);
        $this->assertLessThan(self::STALL_TIMEOUT + 1.0, $processTime);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }
}
