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
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class DbApiExceptionTest extends TestCase
{
    use ServerTestTrait;

    public static function apiVersions(): Iterator
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    /**
     * Each error of EventSourcingDB 1.2.0 that clearly matches an error of the
     * v2 API, as it answers the call that fails with it.
     */
    public static function errorsOfV1WithACode(): Iterator
    {
        yield 'writeEvents, 409, state conflict' => [
            self::writeEvents(...),
            409,
            'state conflict: precondition failed',
            ErrorCode::PRECONDITION_VIOLATED,
        ];
        yield 'writeEvents, 409, exceeded max store capacity' => [
            self::writeEvents(...),
            409,
            'state conflict: exceeded max store capacity',
            ErrorCode::CAPACITY_EXCEEDED,
        ];
        yield 'writeEvents, 409, schema conflict' => [
            self::writeEvents(...),
            409,
            'schema conflict: event candidate does not match schema: expected string, but got number',
            ErrorCode::EVENT_SCHEMA_VIOLATED,
        ];
        yield 'registerEventSchema, 409, schema already exists' => [
            self::registerEventSchema(...),
            409,
            'schema conflict: schema already exists',
            ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED,
        ];
        yield 'registerEventSchema, 409, event with ID' => [
            self::registerEventSchema(...),
            409,
            'schema conflict: event with ID 0: expected string, but got number',
            ErrorCode::EVENT_SCHEMA_VIOLATED,
        ];
        yield 'readEvents, 409, fromLatestEvent beyond upperBound' => [
            static function (Client $client): void {
                iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
            },
            409,
            'state conflict: fromLatestEvent results in an event ID greater than upperBound ID',
            ErrorCode::RANGE_UNSATISFIABLE,
        ];
        yield 'readEventType, 404' => [
            static function (Client $client): void {
                $client->readEventType('io.eventsourcingdb.test');
            },
            404,
            "event type 'io.eventsourcingdb.test' not found",
            ErrorCode::EVENT_TYPE_UNKNOWN,
        ];
        yield 'readEvents, 503, operation mode' => [
            static function (Client $client): void {
                iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
            },
            503,
            'the current operation mode conflicts with the given request: reading is not permitted',
            ErrorCode::STORE_READ_ONLY,
        ];
        yield 'writeEvents, 503, operation mode' => [
            self::writeEvents(...),
            503,
            'the current operation mode conflicts with the given request: writing is not permitted',
            ErrorCode::STORE_READ_ONLY,
        ];
        yield 'writeEvents, 507' => [
            self::writeEvents(...),
            507,
            'insufficient storage',
            ErrorCode::LICENSE_INVALID,
        ];
        yield 'writeEvents, 400' => [
            self::writeEvents(...),
            400,
            'bad request: invalid event candidate',
            '',
        ];
        yield 'readEventType, 400' => [
            static function (Client $client): void {
                $client->readEventType('io.eventsourcingdb.test.');
            },
            400,
            "invalid event type: 'io.eventsourcingdb.test.'",
            '',
        ];
    }

    #[DataProvider('errorsOfV1WithACode')]
    public function testReportsAnErrorOfTheV1ApiWithTheCodeOfTheV2ApiThatClearlyMatchesIt(Closure $call, int $status, string $text, string $code): void
    {
        $address = $this->startDatabase('v1', [
            $this->textResponse($status, $text),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException($call, $client);

        $this->assertSame($code, $dbApiException->errorCode);
        $this->assertSame(0, $dbApiException->getCode());
        // The server ends the text with a line break, which neither the
        // description nor the message keeps.
        $this->assertSame($text, $dbApiException->description);
        $this->assertStringEndsWith(", {$text}", $dbApiException->getMessage());
        $this->assertSame([], $dbApiException->details);
        $this->assertSame($status, $dbApiException->status);
        $this->assertNull($dbApiException->retryAfter);
    }

    public function testReportsAnErrorThatTheV2ApiSendsWithinAStream(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([
                self::item('v2', 'error', [
                    'code' => ErrorCode::PRECONDITION_VIOLATED,
                    'description' => '/preconditions/0: precondition isSubjectPristine does not hold',
                    'details' => [
                        'precondition' => 0,
                    ],
                ]),
                self::item('v2', 'end'),
            ], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(self::writeEvents(...), $client);

        $this->assertSame(ErrorCode::PRECONDITION_VIOLATED, $dbApiException->errorCode);
        $this->assertSame('/preconditions/0: precondition isSubjectPristine does not hold', $dbApiException->description);
        $this->assertSame([
            'precondition' => 0,
        ], $dbApiException->details);
        $this->assertSame(200, $dbApiException->status);
        $this->assertNull($dbApiException->retryAfter);
        $this->assertSame('Failed to write events, /preconditions/0: precondition isSubjectPristine does not hold', $dbApiException->getMessage());
    }

    public function testReportsAnErrorThatTheV2ApiSendsAsItsOnlyAnswer(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([
                self::item('v2', 'error', [
                    'code' => ErrorCode::EVENT_TYPE_UNKNOWN,
                    'description' => 'event type is unknown: it has neither events nor a registered schema',
                    'details' => [
                        'value' => 'io.eventsourcingdb.test',
                    ],
                ]),
            ], interval: 0.0, holdFor: 0.0, contentType: 'application/json'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            $client->readEventType('io.eventsourcingdb.test');
        }, $client);

        $this->assertSame(ErrorCode::EVENT_TYPE_UNKNOWN, $dbApiException->errorCode);
        $this->assertSame('event type is unknown: it has neither events nor a registered schema', $dbApiException->description);
        $this->assertSame([
            'value' => 'io.eventsourcingdb.test',
        ], $dbApiException->details);
        $this->assertSame(200, $dbApiException->status);
    }

    public function testReportsAnErrorThatTheV2ApiAnswersWithAStatus(): void
    {
        $address = $this->startDatabase('v2', [
            $this->textResponse(400, '/foo: unknown field'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        }, $client);

        $this->assertSame('', $dbApiException->errorCode);
        $this->assertSame('/foo: unknown field', $dbApiException->description);
        $this->assertSame([], $dbApiException->details);
        $this->assertSame(400, $dbApiException->status);
        $this->assertSame('Failed to read events, /foo: unknown field', $dbApiException->getMessage());
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testKeepsTheTextOfTheServerApartFromTheLineBreakThatEndsIt(string $apiVersion): void
    {
        // The server ends the text with a line break, which is not a part of
        // it, unlike the spaces around it.
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(400, '  invalid request  '),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        }, $client);

        $this->assertSame('  invalid request  ', $dbApiException->description);
        $this->assertSame('Failed to read events,   invalid request  ', $dbApiException->getMessage());
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testKeepsALineBreakOfTheServerBeforeTheOneThatEndsTheText(string $apiVersion): void
    {
        // The text itself ends with a line break, to which the server appends
        // another one, which alone is not a part of it.
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(400, "invalid request\n"),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        }, $client);

        $this->assertSame("invalid request\n", $dbApiException->description);
    }

    public function testReportsAnErrorThatTheV1ApiSendsWithinAStream(): void
    {
        $address = $this->startDatabase('v1', [
            $this->response([
                self::item('v1', 'error', [
                    'error' => 'failed to read events',
                ]),
            ], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        }, $client);

        $this->assertSame('', $dbApiException->errorCode);
        $this->assertSame('failed to read events', $dbApiException->description);
        $this->assertSame(200, $dbApiException->status);
        $this->assertSame('Failed to read events, failed to read events', $dbApiException->getMessage());
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testReportsTheSecondsAfterWhichARequestThatWasLimitedMayBeRepeated(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(429, 'too many requests', ['Retry-After: 7']),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(self::writeEvents(...), $client);

        $this->assertSame(429, $dbApiException->status);
        $this->assertSame(7, $dbApiException->retryAfter);
        $this->assertSame('', $dbApiException->errorCode);
        $this->assertSame('too many requests', $dbApiException->description);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testReportsNoSecondsForARequestThatWasLimitedWithoutThem(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(429, 'too many requests'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(self::writeEvents(...), $client);

        $this->assertSame(429, $dbApiException->status);
        $this->assertNull($dbApiException->retryAfter);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testReportsNoSecondsForAnErrorOtherThanALimitedRequest(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(503, 'service unavailable', ['Retry-After: 7']),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            iterator_count($client->readEvents('/', new ReadEventsOptions(recursive: true)));
        }, $client);

        $this->assertSame(503, $dbApiException->status);
        $this->assertNull($dbApiException->retryAfter);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testDoesNotRepeatARequestThatWasLimited(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->textResponse(429, 'too many requests', ['Retry-After: 1']),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $this->catchDbApiException(self::writeEvents(...), $client);

        $this->assertCount(2, $this->readRequests(), 'Expected the client to ask for the API, and to send the write once.');
    }

    public function testKeepsTheCodeOfTheErrorApartFromTheIntegerCodeOfTheException(): void
    {
        $address = $this->startDatabase('v2', [
            $this->textResponse(401, 'unauthorized'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $dbApiException = $this->catchDbApiException(static function (Client $client): void {
            $client->verifyApiToken();
        }, $client);

        $this->assertSame(0, $dbApiException->getCode());
        $this->assertSame(401, $dbApiException->status);
    }

    public function testCanBeWrappedTheWayAnyOtherExceptionIs(): void
    {
        $dbApiException = new DbApiException(
            message: 'Failed to write events, /preconditions/0: precondition isSubjectPristine does not hold',
            errorCode: ErrorCode::PRECONDITION_VIOLATED,
            description: '/preconditions/0: precondition isSubjectPristine does not hold',
            details: [
                'precondition' => 0,
            ],
            status: 200,
        );

        // PHP takes the code of an exception as an integer, so wrapping one
        // whose code is not one fails with a TypeError.
        $runtimeException = new RuntimeException($dbApiException->getMessage(), $dbApiException->getCode(), $dbApiException);

        $this->assertSame(ErrorCode::PRECONDITION_VIOLATED, $dbApiException->errorCode);
        $this->assertSame($dbApiException, $runtimeException->getPrevious());
    }

    public function testFailsWithAnExceptionThatExistingCallersCatchAsARuntimeException(): void
    {
        $this->assertTrue(is_a(DbApiException::class, RuntimeException::class, true));
    }

    private static function writeEvents(Client $client): void
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
        ]);
    }

    private static function registerEventSchema(Client $client): void
    {
        $client->registerEventSchema('io.eventsourcingdb.test', [
            'type' => 'object',
        ]);
    }

    private function catchDbApiException(Closure $call, Client $client): DbApiException
    {
        try {
            $call($client);
        } catch (DbApiException $dbApiException) {
            return $dbApiException;
        }

        $this->fail('Expected the call to fail with a DbApiException, but it did not fail.');
    }
}
