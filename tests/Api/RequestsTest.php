<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Api;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Bound;
use Thenativeweb\Eventsourcingdb\BoundType;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\EventType;
use Thenativeweb\Eventsourcingdb\IncompatibleServerException;
use Thenativeweb\Eventsourcingdb\IsEventQlQueryTrue;
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\PreconditionOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

/**
 * Checks what each call sends through each API, and how it reads the answer.
 */
final class RequestsTest extends TestCase
{
    use ServerTestTrait;

    public static function requests(): Iterator
    {
        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );
        $end = self::item('v2', 'end');

        yield 'ping (v1)' => [
            'v1',
            static fn (Client $client) => $client->ping(),
            ['{"type":"io.eventsourcingdb.api.ping-received"}'],
            'application/json',
            ['GET', '/api/v1/ping', ''],
        ];
        yield 'ping (v2)' => [
            'v2',
            static fn (Client $client) => $client->ping(),
            [self::$pingOfV2],
            'application/json',
            ['GET', '/api/v2/system/ping', ''],
        ];
        yield 'verifyApiToken (v1)' => [
            'v1',
            static fn (Client $client) => $client->verifyApiToken(),
            ['{"type":"io.eventsourcingdb.api.api-token-verified"}'],
            'application/json',
            ['POST', '/api/v1/verify-api-token', ''],
        ];
        yield 'verifyApiToken (v2)' => [
            'v2',
            static fn (Client $client) => $client->verifyApiToken(),
            [self::item('v2', 'event', [
                'type' => 'io.eventsourcingdb.api.token-verified',
            ])],
            'application/json',
            ['POST', '/api/v2/system/verify-token', ''],
        ];
        yield 'writeEvents (v1)' => [
            'v1',
            static fn (Client $client): array => $client->writeEvents([$eventCandidate], [new IsSubjectPristine('/test')]),
            ['[]'],
            'application/json',
            ['POST', '/api/v1/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":[{"type":"isSubjectPristine","payload":{"subject":"\/test"}}]}'],
        ];
        yield 'writeEvents (v2)' => [
            'v2',
            static fn (Client $client): array => $client->writeEvents([$eventCandidate], [new IsSubjectPristine('/test')]),
            [$end],
            'application/x-ndjson',
            ['POST', '/api/v2/data/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":[{"type":"isSubjectPristine","payload":{"subject":"\/test"}}]}'],
        ];
        $writeEventsWithPreconditionsOnTheSubject = static fn (?PreconditionOptions $preconditionOptions): Closure => static fn (Client $client): array => $client->writeEvents([$eventCandidate], [
            new IsSubjectPristine('/test', $preconditionOptions),
            new IsSubjectPopulated('/test', $preconditionOptions),
            new IsSubjectOnEventId('/test', '0', $preconditionOptions),
        ]);
        $preconditionsWithoutOptions = '[{"type":"isSubjectPristine","payload":{"subject":"\/test"}},{"type":"isSubjectPopulated","payload":{"subject":"\/test"}},{"type":"isSubjectOnEventId","payload":{"subject":"\/test","eventId":"0"}}]';

        yield 'writeEvents with preconditions on the subject without options (v1)' => [
            'v1',
            $writeEventsWithPreconditionsOnTheSubject(null),
            ['[]'],
            'application/json',
            ['POST', '/api/v1/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":' . $preconditionsWithoutOptions . '}'],
        ];
        yield 'writeEvents with preconditions on the subject without options (v2)' => [
            'v2',
            $writeEventsWithPreconditionsOnTheSubject(null),
            [$end],
            'application/x-ndjson',
            ['POST', '/api/v2/data/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":' . $preconditionsWithoutOptions . '}'],
        ];
        // The v1 API knows no options of a precondition, so a precondition
        // that is not recursive is sent to it as one without options.
        yield 'writeEvents with preconditions on the subject that are not recursive (v1)' => [
            'v1',
            $writeEventsWithPreconditionsOnTheSubject(new PreconditionOptions(recursive: false)),
            ['[]'],
            'application/json',
            ['POST', '/api/v1/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":' . $preconditionsWithoutOptions . '}'],
        ];
        yield 'writeEvents with preconditions on the subject that are not recursive (v2)' => [
            'v2',
            $writeEventsWithPreconditionsOnTheSubject(new PreconditionOptions(recursive: false)),
            [$end],
            'application/x-ndjson',
            ['POST', '/api/v2/data/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":[{"type":"isSubjectPristine","payload":{"subject":"\/test","options":{"recursive":false}}},{"type":"isSubjectPopulated","payload":{"subject":"\/test","options":{"recursive":false}}},{"type":"isSubjectOnEventId","payload":{"subject":"\/test","eventId":"0","options":{"recursive":false}}}]}'],
        ];
        yield 'writeEvents with recursive preconditions on the subject (v2)' => [
            'v2',
            $writeEventsWithPreconditionsOnTheSubject(new PreconditionOptions(recursive: true)),
            [$end],
            'application/x-ndjson',
            ['POST', '/api/v2/data/write-events', '{"events":[{"source":"https:\/\/www.eventsourcingdb.io","subject":"\/test","type":"io.eventsourcingdb.test","data":{"value":23}}],"preconditions":[{"type":"isSubjectPristine","payload":{"subject":"\/test","options":{"recursive":true}}},{"type":"isSubjectPopulated","payload":{"subject":"\/test","options":{"recursive":true}}},{"type":"isSubjectOnEventId","payload":{"subject":"\/test","eventId":"0","options":{"recursive":true}}}]}'],
        ];
        yield 'readEvents (v1)' => [
            'v1',
            static fn (Client $client): int => iterator_count($client->readEvents('/test', new ReadEventsOptions(recursive: false, lowerBound: new Bound('1', BoundType::INCLUSIVE)))),
            [],
            'application/x-ndjson',
            ['POST', '/api/v1/read-events', '{"subject":"\/test","options":{"recursive":false,"lowerBound":{"id":"1","type":"inclusive"}}}'],
        ];
        yield 'readEvents (v2)' => [
            'v2',
            static fn (Client $client): int => iterator_count($client->readEvents('/test', new ReadEventsOptions(recursive: false, lowerBound: new Bound('1', BoundType::INCLUSIVE)))),
            [$end],
            'application/x-ndjson',
            ['QUERY', '/api/v2/data/read-events', '{"subject":"\/test","options":{"recursive":false,"lowerBound":{"id":"1","type":"inclusive"}}}'],
        ];
        yield 'runEventQlQuery (v1)' => [
            'v1',
            static fn (Client $client): int => iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e')),
            [],
            'application/x-ndjson',
            ['POST', '/api/v1/run-eventql-query', '{"query":"FROM e IN events PROJECT INTO e"}'],
        ];
        yield 'runEventQlQuery (v2)' => [
            'v2',
            static fn (Client $client): int => iterator_count($client->runEventQlQuery('FROM e IN events PROJECT INTO e')),
            [$end],
            'application/x-ndjson',
            ['QUERY', '/api/v2/data/run-eventql-query', '{"query":"FROM e IN events PROJECT INTO e"}'],
        ];
        // The server never ends an observation, so it is left after its first
        // event.
        $observeEvents = static function (Client $client): void {
            foreach ($client->observeEvents('/test', new ObserveEventsOptions(recursive: true)) as $event) {
                break;
            }
        };

        yield 'observeEvents (v1)' => [
            'v1',
            $observeEvents,
            [self::eventItem('v1')],
            'application/x-ndjson',
            ['POST', '/api/v1/observe-events', '{"subject":"\/test","options":{"recursive":true}}'],
        ];
        yield 'observeEvents (v2)' => [
            'v2',
            $observeEvents,
            [self::eventItem('v2')],
            'application/x-ndjson',
            ['QUERY', '/api/v2/data/observe-events', '{"subject":"\/test","options":{"recursive":true}}'],
        ];
        yield 'registerEventSchema (v1)' => [
            'v1',
            static fn (Client $client) => $client->registerEventSchema('io.eventsourcingdb.test', [
                'type' => 'object',
            ]),
            [],
            'application/json',
            ['POST', '/api/v1/register-event-schema', '{"eventType":"io.eventsourcingdb.test","schema":{"type":"object"}}'],
        ];
        yield 'registerEventSchema (v2)' => [
            'v2',
            static fn (Client $client) => $client->registerEventSchema('io.eventsourcingdb.test', [
                'type' => 'object',
            ]),
            [self::item('v2', 'event', [
                'type' => 'io.eventsourcingdb.api.event-schema-registered',
            ]), $end],
            'application/x-ndjson',
            ['POST', '/api/v2/data/register-event-schema', '{"type":"io.eventsourcingdb.test","schema":{"type":"object"}}'],
        ];
        yield 'readSubjects (v1)' => [
            'v1',
            static fn (Client $client): int => iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: false))),
            [],
            'application/x-ndjson',
            ['POST', '/api/v1/read-subjects', '{"baseSubject":"\/test"}'],
        ];
        yield 'readSubjects (v2)' => [
            'v2',
            static fn (Client $client): int => iterator_count($client->readSubjects('/test', new ReadSubjectsOptions(recursive: false))),
            [$end],
            'application/x-ndjson',
            ['QUERY', '/api/v2/data/read-subjects', '{"subject":"\/test","options":{"recursive":false}}'],
        ];
        yield 'readEventTypes (v1)' => [
            'v1',
            static fn (Client $client): int => iterator_count($client->readEventTypes()),
            [],
            'application/x-ndjson',
            ['POST', '/api/v1/read-event-types', ''],
        ];
        yield 'readEventTypes (v2)' => [
            'v2',
            static fn (Client $client): int => iterator_count($client->readEventTypes()),
            [$end],
            'application/x-ndjson',
            ['QUERY', '/api/v2/data/read-event-types', ''],
        ];
        yield 'readEventType (v1)' => [
            'v1',
            static fn (Client $client): \Thenativeweb\Eventsourcingdb\EventType => $client->readEventType('io.eventsourcingdb.test'),
            ['{"eventType":"io.eventsourcingdb.test","isPhantom":false}'],
            'application/json',
            ['POST', '/api/v1/read-event-type', '{"eventType":"io.eventsourcingdb.test"}'],
        ];
        yield 'readEventType (v2)' => [
            'v2',
            static fn (Client $client): \Thenativeweb\Eventsourcingdb\EventType => $client->readEventType('io.eventsourcingdb.test'),
            [self::item('v2', 'eventType', [
                'type' => 'io.eventsourcingdb.test',
                'hasEvents' => true,
                'schema' => null,
            ])],
            'application/json',
            ['QUERY', '/api/v2/data/read-event-type', '{"type":"io.eventsourcingdb.test"}'],
        ];
    }

    public static function eventTypes(): Iterator
    {
        yield 'with events (v1)' => [
            'v1',
            '{"eventType":"io.eventsourcingdb.test","isPhantom":false}',
            new EventType('io.eventsourcingdb.test', true),
        ];
        yield 'without events (v1)' => [
            'v1',
            '{"eventType":"io.eventsourcingdb.test","isPhantom":true,"schema":{"type":"object"}}',
            new EventType('io.eventsourcingdb.test', false, [
                'type' => 'object',
            ]),
        ];
        yield 'with events (v2)' => [
            'v2',
            '{"type":"eventType","data":{"type":"io.eventsourcingdb.test","hasEvents":true,"schema":null}}',
            new EventType('io.eventsourcingdb.test', true),
        ];
        yield 'without events (v2)' => [
            'v2',
            '{"type":"eventType","data":{"type":"io.eventsourcingdb.test","hasEvents":false,"schema":{"type":"object"}}}',
            new EventType('io.eventsourcingdb.test', false, [
                'type' => 'object',
            ]),
        ];
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     * @param array{string, string, string} $expectedRequest
     */
    #[DataProvider('requests')]
    public function testSendsTheRequestOfTheApi(string $apiVersion, Closure $call, array $lines, string $contentType, array $expectedRequest): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response($lines, interval: 0.0, holdFor: 0.0, contentType: $contentType),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $call($client);

        $requests = $this->readRequests();
        $this->assertCount(2, $requests);
        $this->assertSame([
            'method' => $expectedRequest[0],
            'path' => $expectedRequest[1],
            'body' => $expectedRequest[2],
        ], $requests[1]);
    }

    /**
     * @param 'v1'|'v2' $apiVersion
     */
    #[DataProvider('eventTypes')]
    public function testReadsAnEventTypeOfTheApi(string $apiVersion, string $answer, EventType $eventType): void
    {
        $address = $this->startDatabase($apiVersion, [
            $this->response([$answer], interval: 0.0, holdFor: 0.0, contentType: 'application/json'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $this->assertEquals($eventType, $client->readEventType('io.eventsourcingdb.test'));
    }

    public static function recursivePreconditions(): Iterator
    {
        $preconditionOptions = new PreconditionOptions(recursive: true);

        yield 'isSubjectPristine' => [new IsSubjectPristine('/test', $preconditionOptions)];
        yield 'isSubjectPopulated' => [new IsSubjectPopulated('/test', $preconditionOptions)];
        yield 'isSubjectOnEventId' => [new IsSubjectOnEventId('/test', '0', $preconditionOptions)];
    }

    #[DataProvider('recursivePreconditions')]
    public function testFailsWithoutSendingAWriteWithARecursivePreconditionThroughTheV1Api(IsSubjectPristine|IsSubjectPopulated|IsSubjectOnEventId $precondition): void
    {
        $address = $this->startDatabase('v1', [
            $this->response(['[]'], interval: 0.0, holdFor: 0.0, contentType: 'application/json'),
        ]);
        $client = new Client("http://{$address}", 'secret');

        try {
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
                new IsEventQlQueryTrue('FROM e IN events PROJECT INTO COUNT() == 0'),
                $precondition,
            ]);
            $this->fail('Expected the write to fail, but it did not.');
        } catch (IncompatibleServerException $incompatibleServerException) {
            $this->assertSame('The server does not offer the v2 API, which a recursive precondition needs.', $incompatibleServerException->getMessage());
        }

        $this->assertCount(1, $this->readRequests(), 'Expected the client to ask for the API, but not to send the write.');
    }

    public function testReadsTheValueOfEachRowOfAQueryThatTheV2ApiAnswers(): void
    {
        $address = $this->startDatabase('v2', [
            $this->response([
                self::item('v2', 'row', [
                    'value' => [
                        'id' => '0',
                    ],
                ]),
                self::item('v2', 'row', [
                    'value' => 42,
                ]),
                self::item('v2', 'row', [
                    'value' => null,
                ]),
                self::item('v2', 'end'),
            ], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $rows = iterator_to_array($client->runEventQlQuery('FROM e IN events PROJECT INTO e'), false);

        $this->assertSame([[
            'id' => '0',
        ], 42, null], $rows);
    }

    public function testListsTheGivenSubjectAloneThroughTheV1ApiIfNotRecursive(): void
    {
        $subjects = array_map(
            static fn (string $subject): string => self::item('v1', 'subject', [
                'subject' => $subject,
            ]),
            ['/test', '/test/1', '/test/2'],
        );
        $address = $this->startDatabase('v1', [
            $this->response($subjects, interval: 0.0, holdFor: 5.0),
            $this->response($subjects, interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjectsRead = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame(['/test'], $subjectsRead);
        $this->assertSame('closed', $this->readServerReport(), 'Expected the listing to end once the subject was found.');

        $subjectsRead = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test', '/test/1', '/test/2'], $subjectsRead);
    }

    public static function subjectsAsTheServerNormalizesThem(): Iterator
    {
        yield 'a subject with a trailing slash' => ['/a/b/', ['/a/b', '/a/b/c'], '/a/b'];
        yield 'the root subject' => ['/', ['/', '/a'], '/'];
    }

    /**
     * @param list<string> $listing
     */
    #[DataProvider('subjectsAsTheServerNormalizesThem')]
    public function testListsTheGivenSubjectAloneThroughTheV1ApiAsTheServerNormalizesIt(string $subject, array $listing, string $expected): void
    {
        $subjects = array_map(
            static fn (string $subject): string => self::item('v1', 'subject', [
                'subject' => $subject,
            ]),
            $listing,
        );
        $address = $this->startDatabase('v1', [
            $this->response($subjects, interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjectsRead = iterator_to_array($client->readSubjects($subject, new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame([$expected], $subjectsRead);
    }

    public function testListsNoSubjectThroughTheV1ApiIfTheGivenOneIsNotListedAndNotRecursive(): void
    {
        $address = $this->startDatabase('v1', [
            $this->response([self::item('v1', 'subject', [
                'subject' => '/test/1',
            ])], interval: 0.0, holdFor: 0.0),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $subjectsRead = iterator_to_array($client->readSubjects('/test', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame([], $subjectsRead);
    }
}
