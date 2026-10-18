<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Api;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
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
            $this->assertSame('', $dbApiException->code);
            $this->assertSame('server is shutting down', $dbApiException->description);
        }
    }

    public static function apiVersions(): Iterator
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
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
