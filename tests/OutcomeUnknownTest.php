<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Api\Transport;
use Thenativeweb\Eventsourcingdb\Api\V1Transport;
use Thenativeweb\Eventsourcingdb\Api\V2Transport;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

/**
 * A write, of events or of an event schema, was certainly not stored only if
 * the connection could not be established at all, or if the server refused
 * it explicitly. Every other failure leaves its outcome open.
 */
final class OutcomeUnknownTest extends TestCase
{
    use ServerTestTrait;

    private const STALL_TIMEOUT = 0.5;

    public static function writes(): Iterator
    {
        yield 'writeEvents' => [self::writeEvents(...)];
        yield 'registerEventSchema' => [self::registerEventSchema(...)];
    }

    public static function transports(): Iterator
    {
        foreach (self::writes() as $name => [$write]) {
            yield "{$name} (v1)" => [
                static fn (HttpClient $httpClient): Transport => new V1Transport($httpClient, 'secret'),
                $write,
            ];
            yield "{$name} (v2)" => [
                static fn (HttpClient $httpClient): Transport => new V2Transport($httpClient, 'secret', static function (): void {}),
                $write,
            ];
        }
    }

    /**
     * The answers with which the server refuses a write explicitly.
     */
    public static function refusals(): Iterator
    {
        foreach (self::writes() as $name => [$write]) {
            foreach ([400, 401, 404, 408, 409, 413, 429, 503, 507] as $status) {
                yield "{$name}, v1, {$status}" => ['v1', $write, self::textAnswer($status), $status];
            }

            foreach ([400, 401, 408, 413, 429, 503] as $status) {
                yield "{$name}, v2, {$status}" => ['v2', $write, self::textAnswer($status), $status];
            }

            foreach ([ErrorCode::PRECONDITION_VIOLATED, ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED, ErrorCode::STORE_READ_ONLY, ErrorCode::LICENSE_INVALID] as $code) {
                yield "{$name}, v2, {$code}" => ['v2', $write, self::errorItemAnswer($code), 200];
            }
        }
    }

    /**
     * The failures that leave the outcome of a write open, with the type of
     * their cause.
     */
    public static function unknownOutcomes(): Iterator
    {
        foreach (self::writes() as $name => [$write]) {
            foreach (['v1', 'v2'] as $apiVersion) {
                foreach ([500, 502, 504] as $status) {
                    yield "{$name}, {$apiVersion}, {$status}" => [$apiVersion, $write, self::textAnswer($status), DbApiException::class];
                }

                yield "{$name}, {$apiVersion}, a connection that breaks after the request was sent" => [
                    $apiVersion,
                    $write,
                    [
                        'dropConnection' => true,
                    ],
                    RuntimeException::class,
                ];
                yield "{$name}, {$apiVersion}, a stall" => [
                    $apiVersion,
                    $write,
                    [
                        'delay' => 5.0,
                    ],
                    ConnectionStalledException::class,
                ];
                yield "{$name}, {$apiVersion}, an answer that is not from EventSourcingDB" => [
                    $apiVersion,
                    $write,
                    [
                        'lines' => ['Bad Gateway'],
                        'contentType' => 'text/plain',
                        'status' => 502,
                        'server' => 'nginx',
                    ],
                    RuntimeException::class,
                ];
            }

            yield "{$name}, v2, an error in storing the write" => ['v2', $write, self::errorItemAnswer(ErrorCode::STORE_FAILED), DbApiException::class];
            yield "{$name}, v2, a stream that ends without its end item" => [
                'v2',
                $write,
                [
                    'lines' => [self::item('v2', 'heartbeat')],
                ],
                RuntimeException::class,
            ];
            yield "{$name}, v2, a route that the server does not know" => ['v2', $write, self::textAnswer(404), DbApiException::class];
        }

        yield 'writeEvents, v1, an answer that breaks off' => [
            'v1',
            self::writeEvents(...),
            [
                'lines' => ['[{"specversion":"1.0","id":"0"'],
                'contentType' => 'application/json',
            ],
            RuntimeException::class,
        ];
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('refusals')]
    public function testReportsAWriteThatTheServerRefusedAsNotStored(string $apiVersion, Closure $write, array $answer, int $status): void
    {
        $address = $this->startDatabase($apiVersion, [
            [...$this->response([], interval: 0.0, holdFor: 0.0), ...$answer],
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $write($client);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame($status, $dbApiException->status);
        }
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     * @param class-string<RuntimeException> $causeClass
     */
    #[DataProvider('unknownOutcomes')]
    public function testReportsAWriteThatMayHaveBeenStoredAsUnknown(string $apiVersion, Closure $write, array $answer, string $causeClass): void
    {
        $address = $this->startDatabase($apiVersion, [
            [...$this->response([], interval: 0.0, holdFor: 0.0), ...$answer],
        ]);
        $client = new Client("http://{$address}", 'secret', stallTimeout: self::STALL_TIMEOUT);

        $this->assertOutcomeIsUnknown($write, $client, $causeClass);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('unknownOutcomesOfAnAbort')]
    public function testReportsAWriteWhoseAnswerWasAbortedAsUnknown(string $apiVersion, Closure $write): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([], interval: 0.0, holdFor: 5.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $client->abortIn(0.2);

        $this->assertOutcomeIsUnknown($write, $client, RuntimeException::class);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the connection to be closed.');
    }

    public static function unknownOutcomesOfAnAbort(): Iterator
    {
        // Registering an event schema through the v1 API does not read the
        // answer, so there is nothing to abort.
        yield 'writeEvents (v1)' => ['v1', self::writeEvents(...)];
        yield 'writeEvents (v2)' => ['v2', self::writeEvents(...)];
        yield 'registerEventSchema (v2)' => ['v2', self::registerEventSchema(...)];
    }

    /**
     * @param Closure(HttpClient): Transport $createTransport
     */
    #[DataProvider('transports')]
    public function testReportsAWriteToAHostThatDoesNotExistAsNotStored(Closure $createTransport, Closure $write): void
    {
        $transport = $createTransport(new HttpClient('http://non-existent-host.invalid'));

        $this->assertIsNotSent($write, $transport);
    }

    /**
     * @param Closure(HttpClient): Transport $createTransport
     */
    #[DataProvider('transports')]
    public function testReportsAWriteThatTheHostRefusedToConnectAsNotStored(Closure $createTransport, Closure $write): void
    {
        // A port that was just free, and that nothing listens on any more.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        $transport = $createTransport(new HttpClient("http://{$address}"));

        $this->assertIsNotSent($write, $transport);
    }

    /**
     * @param Closure(HttpClient): Transport $createTransport
     */
    #[DataProvider('transports')]
    public function testReportsAWriteWhoseTlsHandshakeDoesNotFinishAsNotStored(Closure $createTransport, Closure $write): void
    {
        // The server does not answer the TLS handshake, so the connection is
        // never established, and the request is never sent.
        $address = $this->startServer([], interval: 0.0, holdFor: 0.0);

        $transport = $createTransport(new HttpClient("https://{$address}", self::STALL_TIMEOUT));

        try {
            $write($transport);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertInstanceOf(ConnectionStalledException::class, $runtimeException, $runtimeException->getMessage());
        }
    }

    public function testNamesTheCauseOfAnUnknownOutcome(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([], interval: 0.0, holdFor: 0.0, dropConnection: true),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            self::writeEvents($client);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (OutcomeUnknownException $outcomeUnknownException) {
            $this->assertStringStartsWith('It is unknown whether the events were written: Internal HttpClient: cURL handle execution failed with error: ', $outcomeUnknownException->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $outcomeUnknownException->getPrevious());
        }
    }

    private static function writeEvents(Client|Transport $client): void
    {
        $events = [
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
        ];

        if ($client instanceof Client) {
            $client->writeEvents($events);

            return;
        }

        $client->writeEvents($events, []);
    }

    private static function registerEventSchema(Client|Transport $client): void
    {
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);
    }

    private static function textAnswer(int $status): array
    {
        return [
            'lines' => ["the server answered with status {$status}"],
            'contentType' => 'text/plain; charset=utf-8',
            'status' => $status,
        ];
    }

    private static function errorItemAnswer(string $code): array
    {
        return [
            'lines' => [
                self::item('v2', 'error', [
                    'code' => $code,
                    'description' => 'the write failed',
                    'details' => (object) [],
                ]),
                self::item('v2', 'end'),
            ],
        ];
    }

    /**
     * @param class-string<RuntimeException> $causeClass
     */
    private function assertOutcomeIsUnknown(Closure $write, Client $client, string $causeClass): void
    {
        try {
            $write($client);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (OutcomeUnknownException $outcomeUnknownException) {
            $this->assertInstanceOf($causeClass, $outcomeUnknownException->getPrevious());
        }
    }

    private function assertIsNotSent(Closure $write, Transport $transport): void
    {
        try {
            $write($transport);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $runtimeException, $runtimeException->getMessage());
            $this->assertStringStartsWith('Internal HttpClient: cURL handle execution failed with error: ', $runtimeException->getMessage());
        }
    }
}
