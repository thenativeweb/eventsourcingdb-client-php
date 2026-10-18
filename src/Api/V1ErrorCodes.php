<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Thenativeweb\Eventsourcingdb\ErrorCode;

/**
 * Assigns the codes of the v2 API to those errors of the v1 API that clearly
 * match one, by the texts that EventSourcingDB 1.2.0 sends. Every other error
 * of the v1 API keeps an empty code.
 *
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Api\V1ErrorCodesTest
 */
final class V1ErrorCodes
{
    public const WRITE_EVENTS = 'writeEvents';
    public const REGISTER_EVENT_SCHEMA = 'registerEventSchema';
    public const READ_EVENTS = 'readEvents';
    public const READ_EVENT_TYPE = 'readEventType';
    public const OTHER = 'other';

    /**
     * The errors of a call with the status 409 that match an error of the v2
     * API, by the start of their text, with its code.
     */
    private const CONFLICTS = [
        self::WRITE_EVENTS => [
            'state conflict: precondition failed' => ErrorCode::PRECONDITION_VIOLATED,
            'state conflict: exceeded max store capacity' => ErrorCode::CAPACITY_EXCEEDED,
            'schema conflict: event candidate does not match schema' => ErrorCode::EVENT_SCHEMA_VIOLATED,
        ],
        self::REGISTER_EVENT_SCHEMA => [
            'schema conflict: schema already exists' => ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED,
            'schema conflict: event with ID' => ErrorCode::EVENT_SCHEMA_VIOLATED,
        ],
        self::READ_EVENTS => [
            'state conflict: fromLatestEvent results in an event ID greater than upperBound ID' => ErrorCode::RANGE_UNSATISFIABLE,
        ],
    ];

    /**
     * @param string $call the call that failed, one of the constants of this class
     */
    public static function getCode(string $call, int $status, string $description): string
    {
        if ($status === 503 && str_starts_with($description, 'the current operation mode conflicts with the given request')) {
            return ErrorCode::STORE_READ_ONLY;
        }

        if ($status === 409) {
            foreach (self::CONFLICTS[$call] ?? [] as $start => $code) {
                if (str_starts_with($description, $start)) {
                    return $code;
                }
            }

            return '';
        }

        return match (true) {
            $call === self::READ_EVENT_TYPE && $status === 404 => ErrorCode::EVENT_TYPE_UNKNOWN,
            $call === self::WRITE_EVENTS && $status === 507 => ErrorCode::LICENSE_INVALID,
            default => '',
        };
    }
}
