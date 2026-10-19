<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Api;

use Closure;
use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Api\ProbingHttpClient;
use Thenativeweb\Eventsourcingdb\Api\TransportSelector;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class TransportSelectorTest extends TestCase
{
    use ServerTestTrait;

    private const PROBE = [
        'method' => 'GET',
        'path' => '/api/v2/system/ping',
        'body' => '',
    ];

    public static function answersOfAServerWithoutTheV2Api(): Iterator
    {
        // EventSourcingDB 1.2.0 answers a route of the v2 API that it does
        // not know with 404, and, with its UI enabled, some with 405.
        yield '404' => [404];
        yield '405' => [405];
    }

    public static function answersThatLeaveTheApiOpen(): Iterator
    {
        yield 'a dropped connection' => [
            [
                'lines' => [],
                'interval' => 0.0,
                'holdFor' => 0.0,
                'dropConnection' => true,
            ],
            RuntimeException::class,
        ];
        yield 'a status of 503' => [
            [
                'lines' => ['server is shutting down'],
                'contentType' => 'text/plain; charset=utf-8',
                'status' => 503,
            ],
            DbApiException::class,
        ];
        yield 'a status of 500' => [
            [
                'lines' => ['internal server error'],
                'contentType' => 'text/plain; charset=utf-8',
                'status' => 500,
            ],
            DbApiException::class,
        ];
        yield 'a ping that is not one of the v2 API' => [
            [
                'lines' => ['{"type":"io.eventsourcingdb.api.ping-received"}'],
                'contentType' => 'application/json',
            ],
            RuntimeException::class,
        ];
    }

    public function testUsesTheV2ApiIfTheServerAnswersItsPing(): void
    {
        $address = $this->startDatabase('v2', [
            $this->subjectsResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame([
            self::PROBE,
            [
                'method' => 'QUERY',
                'path' => '/api/v2/data/read-subjects',
                'body' => '{"subject":"\/test","options":{"recursive":true}}',
            ],
        ], $this->readRequests());
    }

    #[DataProvider('answersOfAServerWithoutTheV2Api')]
    public function testUsesTheV1ApiIfTheServerDoesNotKnowThePingOfV2(int $status): void
    {
        $address = $this->startServerWithResponses([
            $this->textResponse($status, 'not found'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame([
            self::PROBE,
            [
                'method' => 'POST',
                'path' => '/api/v1/read-subjects',
                'body' => '{"baseSubject":"\/test"}',
            ],
        ], $this->readRequests());
    }

    #[DataProvider('answersOfAServerWithoutTheV2Api')]
    public function testUsesTheV1ApiIfAnotherServerInFrontOfTheDatabaseDoesNotKnowThePingOfV2(int $status): void
    {
        // A proxy that passes on only the routes of the v1 API answers every
        // other route itself.
        $address = $this->startServerWithResponses([
            $this->textResponse($status, 'not found', server: 'nginx'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame('POST', $this->readRequests()[1]['method']);
    }

    #[DataProvider('answersOfAServerWithoutTheV2Api')]
    public function testUsesTheV1ApiIfAnotherServerInFrontOfTheDatabaseAnswersThePingOfV2WithAPage(int $status): void
    {
        // A proxy such as nginx answers a route it does not pass on with a
        // page of its own.
        $address = $this->startServerWithResponses([
            $this->response(
                ["<html>\r\n<head><title>{$status} Not Found</title></head>\r\n<body>\r\n<center><h1>{$status} Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>"],
                interval: 0.0,
                holdFor: 0.0,
                server: 'nginx',
                contentType: 'text/html',
                status: $status,
            ),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame('POST', $this->readRequests()[1]['method']);
    }

    public function testChecksThatTheV1ApiIsAnsweredByTheDatabaseIfAnotherServerDoesNotKnowThePingOfV2(): void
    {
        $address = $this->startServerWithResponses([
            $this->textResponse(404, 'not found', server: 'nginx'),
            $this->textResponse(404, 'not found', server: 'nginx'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
            $this->fail('Expected the request to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Server must be EventSourcingDB.', $runtimeException->getMessage());
        }

        $this->assertSame('/api/v1/read-subjects', $this->readRequests()[1]['path']);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('apiVersions')]
    public function testRemembersTheApiForTheClient(string $apiVersion): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->subjectsResponse($apiVersion),
            $this->subjectsResponse($apiVersion),
        ]);
        $client = new Client("http://{$address}", 'secret');

        iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
        iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));

        $requests = $this->readRequests();
        $this->assertCount(3, $requests);
        $this->assertSame(self::PROBE, $requests[0]);
        $this->assertNotContains(self::PROBE, array_slice($requests, 1), 'Expected the client to ask only once.');
    }

    public function testAsksOnceForRequestsThatStartedBeforeTheClientKnewTheApi(): void
    {
        $address = $this->startDatabase('v2', [
            $this->subjectsResponse('v2'),
            $this->subjectsResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        // Neither request has been sent yet when the other one starts.
        $first = $client->readSubjects('/test', new ReadSubjectsOptions(recursive: true));
        $second = $client->readSubjects('/test', new ReadSubjectsOptions(recursive: true));

        $subjectsRead = 0;
        foreach ($first as $subject) {
            ++$subjectsRead;
            $subjectsRead += iterator_count($second);
        }

        $this->assertSame(2, $subjectsRead);

        $requests = $this->readRequests();
        $this->assertCount(3, $requests);
        $this->assertSame(self::PROBE, $requests[0]);
        $this->assertNotContains(self::PROBE, array_slice($requests, 1), 'Expected the client to ask only once.');
    }

    public function testAsksAgainForAnotherClient(): void
    {
        $address = $this->startDatabase('v2', [
            $this->subjectsResponse('v2'),
            $this->probeResponse('v2'),
            $this->subjectsResponse('v2'),
        ]);

        iterator_count((new Client("http://{$address}", 'secret'))->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
        iterator_count((new Client("http://{$address}", 'secret'))->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));

        $requests = $this->readRequests();
        $this->assertCount(4, $requests);
        $this->assertSame(self::PROBE, $requests[0]);
        $this->assertSame(self::PROBE, $requests[2]);
    }

    /**
     * @param class-string<RuntimeException> $exceptionClass
     */
    #[DataProvider('answersThatLeaveTheApiOpen')]
    public function testFailsTheRequestAndAsksAgainIfTheAnswerLeavesTheApiOpen(array $answer, string $exceptionClass): void
    {
        $address = $this->startServerWithResponses([
            [...$this->response([], interval: 0.0, holdFor: 0.0), ...$answer],
            $this->probeResponse('v2'),
            $this->subjectsResponse('v2'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
            $this->fail('Expected the request to fail, but it did not.');
        } catch (RuntimeException $runtimeException) {
            $this->assertInstanceOf($exceptionClass, $runtimeException, $runtimeException->getMessage());
        }

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);

        $requests = $this->readRequests();
        $this->assertCount(3, $requests);
        $this->assertSame(self::PROBE, $requests[0]);
        $this->assertSame(self::PROBE, $requests[1], 'Expected the client to ask again.');
    }

    public function testReportsTheStatusAndTheTextOfAnAnswerThatLeavesTheApiOpen(): void
    {
        $address = $this->startServerWithResponses([
            $this->textResponse(503, 'server is shutting down'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $client->ping();
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(503, $dbApiException->status);
            $this->assertSame('', $dbApiException->errorCode);
            $this->assertSame('server is shutting down', $dbApiException->description);
        }
    }

    public static function callsThroughTheV2Api(): Iterator
    {
        $calls = [
            'ping' => static fn (Client $client) => $client->ping(),
            'verifyApiToken' => static fn (Client $client) => $client->verifyApiToken(),
            'readEvents' => static fn (Client $client): int => iterator_count($client->readEvents('/test', new ReadEventsOptions(recursive: true))),
            'runEventQlQuery' => static fn (Client $client): int => iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e')),
            'observeEvents' => static fn (Client $client): int => iterator_count($client->observeEvents('/test', new ObserveEventsOptions(recursive: true))),
            'readSubjects' => static fn (Client $client): int => iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true))),
            'readEventTypes' => static fn (Client $client): int => iterator_count($client->readEventTypes()),
            'readEventType' => static fn (Client $client): EventType => $client->readEventType('io.eventsourcingdb.test'),
        ];

        foreach ($calls as $name => $call) {
            foreach (self::answersOfAServerWithoutTheV2Api() as [$status]) {
                yield "{$name}, {$status}" => [$call, $status];
            }
        }
    }

    public static function writesThroughTheV2Api(): Iterator
    {
        $writes = [
            'writeEvents' => static fn (Client $client): array => $client->writeEvents([
                new EventCandidate(
                    source: 'https://www.eventsourcingdb.io',
                    subject: '/test',
                    type: 'io.eventsourcingdb.test',
                    data: [
                        'value' => 23,
                    ],
                ),
            ]),
            'registerEventSchema' => static fn (Client $client) => $client->registerEventSchema('io.eventsourcingdb.test', [
                'type' => 'object',
            ]),
        ];

        foreach ($writes as $name => $write) {
            foreach (self::answersOfAServerWithoutTheV2Api() as [$status]) {
                yield "{$name}, {$status}" => [$write, $status];
            }
        }
    }

    /**
     * The answers of another server in front of the database that passes on
     * only the routes of the v1 API, with the error that the request fails
     * with, as for any answer that does not come from EventSourcingDB.
     */
    public static function answersOfAnotherServerWithoutTheV2Api(): Iterator
    {
        foreach (self::answersOfAServerWithoutTheV2Api() as [$status]) {
            yield "{$status} as text" => [
                [
                    'lines' => ['not found'],
                    'server' => 'nginx',
                    'contentType' => 'text/plain; charset=utf-8',
                    'status' => $status,
                ],
                'Server must be EventSourcingDB.',
            ];
            yield "{$status} as a page" => [
                [
                    'lines' => ["<html>\r\n<head><title>{$status} Not Found</title></head>\r\n<body>\r\n<center><h1>{$status} Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>"],
                    'server' => 'nginx',
                    'contentType' => 'text/html',
                    'status' => $status,
                ],
                "Internal HttpClient: got Content-Type 'text/html', expected one of: application/json, application/x-ndjson, text/plain",
            ];
        }
    }

    #[DataProvider('callsThroughTheV2Api')]
    public function testFailsTheRequestAndAsksAgainOnceTheServerDoesNotKnowARouteOfTheV2Api(Closure $call, int $status): void
    {
        // A server that was rolled back to EventSourcingDB 1.2.0 answers the
        // routes of the v2 API as it answers its ping.
        $address = $this->startDatabase('v2', [
            $this->textResponse($status, 'not found'),
            $this->probeResponse('v1'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $call($client);
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame($status, $dbApiException->status);
            $this->assertSame('not found', $dbApiException->description);
        }

        $this->assertAsksAgainAndUsesTheV1Api($client);
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('answersOfAnotherServerWithoutTheV2Api')]
    public function testFailsTheRequestAndAsksAgainOnceAnotherServerInFrontOfTheDatabaseDoesNotKnowARouteOfTheV2Api(array $answer, string $message): void
    {
        $address = $this->startDatabase('v2', [
            [...$this->response([], interval: 0.0, holdFor: 0.0), ...$answer],
            $this->probeResponse('v1'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
            $this->fail('Expected the request to fail, but it did not.');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertAsksAgainAndUsesTheV1Api($client);
    }

    #[DataProvider('writesThroughTheV2Api')]
    public function testReportsAWriteAsUnknownAndAsksAgainOnceTheServerDoesNotKnowARouteOfTheV2Api(Closure $write, int $status): void
    {
        $address = $this->startDatabase('v2', [
            $this->textResponse($status, 'not found'),
            $this->probeResponse('v1'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $write($client);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (OutcomeUnknownException $outcomeUnknownException) {
            $cause = $outcomeUnknownException->getPrevious();
            $this->assertInstanceOf(DbApiException::class, $cause);
            $this->assertSame($status, $cause->status);
        }

        $this->assertAsksAgainAndUsesTheV1Api($client);
    }

    public function testDoesNotAskAgainIfTheV2ApiReportsAnErrorWithTheStatus200(): void
    {
        // The v2 API reports that it does not know an event type in the body
        // of its answer, so the client keeps using it, unlike after the 404
        // that follows.
        $address = $this->startDatabase('v2', [
            $this->response([self::item('v2', 'error', [
                'code' => ErrorCode::EVENT_TYPE_UNKNOWN,
                'description' => 'event type not found',
                'details' => (object) [],
            ])], interval: 0.0, holdFor: 0.0, contentType: 'application/json'),
            $this->textResponse(404, 'not found'),
            $this->probeResponse('v1'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            $client->readEventType('io.eventsourcingdb.test');
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(200, $dbApiException->status);
            $this->assertSame(ErrorCode::EVENT_TYPE_UNKNOWN, $dbApiException->errorCode);
        }

        $this->assertFailsWithStatus(404, static fn (): int => iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true))));

        $this->assertAsksAgainAndUsesTheV1Api($client, requestsBefore: ['/api/v2/data/read-event-type']);
    }

    public function testDoesNotAskAgainIfTheV1ApiAnswersWith404(): void
    {
        // Once the client uses the v1 API after a rollback, a 404 through it,
        // such as for an event type that the server does not know, is an
        // error of the request, so the client keeps using the v1 API.
        $address = $this->startDatabase('v2', [
            $this->textResponse(404, 'not found'),
            $this->probeResponse('v1'),
            $this->textResponse(404, 'event type not found'),
            $this->subjectsResponse('v1'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
            iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)));
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(404, $dbApiException->status);
        }

        try {
            $client->readEventType('io.eventsourcingdb.test');
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(404, $dbApiException->status);
            $this->assertSame(ErrorCode::EVENT_TYPE_UNKNOWN, $dbApiException->errorCode);
        }

        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame([
            self::PROBE['path'],
            '/api/v2/data/read-subjects',
            self::PROBE['path'],
            '/api/v1/read-event-type',
            '/api/v1/read-subjects',
        ], array_column($this->readRequests(), 'path'), 'Expected the client to ask only once more.');
    }

    public function testKeepsWhatAskingAgainFoundIfAnotherAnswerThroughTheForgottenApiArrivesLater(): void
    {
        // PHP runs one request at a time, so asking never overlaps with a
        // request that waits for its answer. Still, a 404 through the API
        // that the client has forgotten already, and asked for again since,
        // leaves the transport that asking again chose as it is.
        $address = $this->startDatabase('v2', [
            $this->textResponse(404, 'not found'),
            $this->probeResponse('v2'),
            $this->textResponse(404, 'not found'),
            $this->subjectsResponse('v2'),
        ]);
        $transportSelector = new TransportSelector(new HttpClient("http://{$address}"), 'secret', new ProbingHttpClient("http://{$address}"));

        $transport = $transportSelector->getTransport();
        $this->assertFailsWithStatus(404, static fn (): EventType => $transport->readEventType('io.eventsourcingdb.test'));

        $transportFoundAgain = $transportSelector->getTransport();
        $this->assertNotSame($transport, $transportFoundAgain, 'Expected the client to ask again.');

        $this->assertFailsWithStatus(404, static fn (): EventType => $transport->readEventType('io.eventsourcingdb.test'));

        $this->assertSame($transportFoundAgain, $transportSelector->getTransport(), 'Expected the client to keep what asking again found.');

        $subjects = iterator_to_array($transportFoundAgain->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
        $this->assertSame([
            self::PROBE['path'],
            '/api/v2/data/read-event-type',
            self::PROBE['path'],
            '/api/v2/data/read-event-type',
            '/api/v2/data/read-subjects',
        ], array_column($this->readRequests(), 'path'));
    }

    public static function apiVersions(): Iterator
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    /**
     * Asserts that the next request of the client asks again, and then uses
     * the v1 API, after the client asked once, sent the requests of the given
     * paths, and then a request through the v2 API that failed.
     *
     * @param list<string> $requestsBefore
     */
    private function assertAsksAgainAndUsesTheV1Api(Client $client, array $requestsBefore = []): void
    {
        $subjects = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);

        $paths = array_column($this->readRequests(), 'path');
        $failedRequest = count($requestsBefore) + 1;

        $this->assertCount($failedRequest + 3, $paths);
        $this->assertSame([self::PROBE['path'], ...$requestsBefore], array_slice($paths, 0, $failedRequest), 'Expected the client to ask only once before.');
        $this->assertStringStartsWith('/api/v2/', $paths[$failedRequest]);
        $this->assertSame([self::PROBE['path'], '/api/v1/read-subjects'], array_slice($paths, $failedRequest + 1), 'Expected the client to ask again.');
    }

    private function assertFailsWithStatus(int $status, Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected the request to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame($status, $dbApiException->status);
        }
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    private function subjectsResponse(string $apiVersion): array
    {
        $lines = [self::item($apiVersion, 'subject', [
            'subject' => '/test',
        ])];

        if ($apiVersion === 'v2') {
            $lines[] = self::item($apiVersion, 'end');
        }

        return $this->response($lines, interval: 0.0, holdFor: 0.0);
    }
}
