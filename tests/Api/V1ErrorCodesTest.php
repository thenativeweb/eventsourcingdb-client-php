<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Api;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Api\V1ErrorCodes;
use Thenativeweb\Eventsourcingdb\ErrorCode;

final class V1ErrorCodesTest extends TestCase
{
    /**
     * The errors of EventSourcingDB 1.2.0 that clearly match an error of the
     * v2 API, with the texts it sends for them.
     */
    public static function errorsWithACode(): Iterator
    {
        yield 'a precondition that does not hold' => [
            V1ErrorCodes::WRITE_EVENTS,
            409,
            'state conflict: precondition failed',
            ErrorCode::PRECONDITION_VIOLATED,
        ];
        yield 'a store whose capacity is exceeded' => [
            V1ErrorCodes::WRITE_EVENTS,
            409,
            'state conflict: exceeded max store capacity',
            ErrorCode::CAPACITY_EXCEEDED,
        ];
        yield 'an event that does not match its schema' => [
            V1ErrorCodes::WRITE_EVENTS,
            409,
            'schema conflict: event candidate does not match schema: expected string, but got number',
            ErrorCode::EVENT_SCHEMA_VIOLATED,
        ];
        yield 'a schema that is already registered' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            409,
            'schema conflict: schema already exists',
            ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED,
        ];
        yield 'a schema that an event does not match' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            409,
            'schema conflict: event with ID 0: expected string, but got number',
            ErrorCode::EVENT_SCHEMA_VIOLATED,
        ];
        yield 'a range that cannot be satisfied' => [
            V1ErrorCodes::READ_EVENTS,
            409,
            'state conflict: fromLatestEvent results in an event ID greater than upperBound ID',
            ErrorCode::RANGE_UNSATISFIABLE,
        ];
        yield 'an event type that is unknown' => [
            V1ErrorCodes::READ_EVENT_TYPE,
            404,
            "event type 'io.eventsourcingdb.test' not found",
            ErrorCode::EVENT_TYPE_UNKNOWN,
        ];
        yield 'a write while the store is read-only' => [
            V1ErrorCodes::WRITE_EVENTS,
            503,
            'the current operation mode conflicts with the given request: writing is not permitted',
            ErrorCode::STORE_READ_ONLY,
        ];
        yield 'a read while the store is read-only' => [
            V1ErrorCodes::OTHER,
            503,
            'the current operation mode conflicts with the given request: reading is not permitted',
            ErrorCode::STORE_READ_ONLY,
        ];
        yield 'a registration while the store is read-only' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            503,
            'the current operation mode conflicts with the given request',
            ErrorCode::STORE_READ_ONLY,
        ];
        yield 'a write without a valid license' => [
            V1ErrorCodes::WRITE_EVENTS,
            507,
            'insufficient storage',
            ErrorCode::LICENSE_INVALID,
        ];
    }

    /**
     * Errors that look like one of the above, but are not, since they belong
     * to another call, have another status, or another text.
     */
    public static function errorsWithoutACode(): Iterator
    {
        yield 'another state conflict of a write' => [
            V1ErrorCodes::WRITE_EVENTS,
            409,
            'state conflict: something else',
        ];
        yield 'a range that cannot be satisfied for another call' => [
            V1ErrorCodes::OTHER,
            409,
            'state conflict: fromLatestEvent results in an event ID greater than upperBound ID',
        ];
        yield 'a precondition that does not hold for another call' => [
            V1ErrorCodes::READ_EVENTS,
            409,
            'state conflict: precondition failed',
        ];
        yield 'an event that does not match its schema for a registration' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            409,
            'schema conflict: event candidate does not match schema: expected string, but got number',
        ];
        yield 'a schema that is already registered for a write' => [
            V1ErrorCodes::WRITE_EVENTS,
            409,
            'schema conflict: schema already exists',
        ];
        yield 'a schema that is already registered with another status' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            400,
            'schema conflict: schema already exists',
        ];
        yield 'an unknown route' => [
            V1ErrorCodes::OTHER,
            404,
            '404 page not found',
        ];
        yield 'an unknown route of a write' => [
            V1ErrorCodes::WRITE_EVENTS,
            404,
            '404 page not found',
        ];
        yield 'another 503' => [
            V1ErrorCodes::WRITE_EVENTS,
            503,
            'the server is shutting down',
        ];
        yield 'a 507 of another call' => [
            V1ErrorCodes::REGISTER_EVENT_SCHEMA,
            507,
            'insufficient storage',
        ];
        yield 'an invalid request' => [
            V1ErrorCodes::WRITE_EVENTS,
            400,
            'bad request: invalid event candidate',
        ];
        yield 'an internal error' => [
            V1ErrorCodes::WRITE_EVENTS,
            500,
            '',
        ];
    }

    #[DataProvider('errorsWithACode')]
    public function testAssignsTheCodeOfTheV2ApiToAnErrorThatClearlyMatchesIt(string $call, int $status, string $description, string $code): void
    {
        $this->assertSame($code, V1ErrorCodes::getCode($call, $status, $description));
    }

    #[DataProvider('errorsWithoutACode')]
    public function testAssignsNoCodeToAnyOtherError(string $call, int $status, string $description): void
    {
        $this->assertSame('', V1ErrorCodes::getCode($call, $status, $description));
    }
}
