<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

/**
 * The codes of the errors that EventSourcingDB reports, as the errorCode of a
 * DbApiException holds them, so that a caller can tell errors apart without
 * comparing their descriptions. Each code is the URL of the page that
 * documents the error.
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\ErrorCodeTest
 */
final class ErrorCode
{
    public const SUBJECT_MISSING = 'https://docs.eventsourcingdb.io/errors/subject-missing';
    public const SUBJECT_MALFORMED = 'https://docs.eventsourcingdb.io/errors/subject-malformed';
    public const ORDER_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/order-unknown';
    public const EVENT_ID_MISSING = 'https://docs.eventsourcingdb.io/errors/event-id-missing';
    public const EVENT_ID_MALFORMED = 'https://docs.eventsourcingdb.io/errors/event-id-malformed';
    public const BOUND_TYPE_MISSING = 'https://docs.eventsourcingdb.io/errors/bound-type-missing';
    public const BOUND_TYPE_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/bound-type-unknown';
    public const RANGE_UNSATISFIABLE = 'https://docs.eventsourcingdb.io/errors/range-unsatisfiable';
    public const FROM_LATEST_EVENT_INCOMPLETE = 'https://docs.eventsourcingdb.io/errors/from-latest-event-incomplete';
    public const IF_EVENT_IS_MISSING_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/if-event-is-missing-unknown';
    public const SELECTION_CONFLICTING = 'https://docs.eventsourcingdb.io/errors/selection-conflicting';
    public const EVENT_TYPE_MISSING = 'https://docs.eventsourcingdb.io/errors/event-type-missing';
    public const EVENT_TYPE_MALFORMED = 'https://docs.eventsourcingdb.io/errors/event-type-malformed';
    public const EVENT_TYPE_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/event-type-unknown';
    public const SOURCE_MISSING = 'https://docs.eventsourcingdb.io/errors/source-missing';
    public const SOURCE_MALFORMED = 'https://docs.eventsourcingdb.io/errors/source-malformed';
    public const DATA_MISSING = 'https://docs.eventsourcingdb.io/errors/data-missing';
    public const TRACEPARENT_MALFORMED = 'https://docs.eventsourcingdb.io/errors/traceparent-malformed';
    public const TRACESTATE_MALFORMED = 'https://docs.eventsourcingdb.io/errors/tracestate-malformed';
    public const TRACESTATE_WITHOUT_TRACEPARENT = 'https://docs.eventsourcingdb.io/errors/tracestate-without-traceparent';
    public const EVENTS_MISSING = 'https://docs.eventsourcingdb.io/errors/events-missing';
    public const PRECONDITION_TYPE_MISSING = 'https://docs.eventsourcingdb.io/errors/precondition-type-missing';
    public const PRECONDITION_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/precondition-unknown';
    public const PAYLOAD_MISSING = 'https://docs.eventsourcingdb.io/errors/payload-missing';
    public const LICENSE_INVALID = 'https://docs.eventsourcingdb.io/errors/license-invalid';
    public const CAPACITY_EXCEEDED = 'https://docs.eventsourcingdb.io/errors/capacity-exceeded';
    public const EVENT_SCHEMA_VIOLATED = 'https://docs.eventsourcingdb.io/errors/event-schema-violated';
    public const EVENT_SCHEMA_UNREGISTERED = 'https://docs.eventsourcingdb.io/errors/event-schema-unregistered';
    public const EVENT_SCHEMA_MISSING = 'https://docs.eventsourcingdb.io/errors/event-schema-missing';
    public const EVENT_SCHEMA_MALFORMED = 'https://docs.eventsourcingdb.io/errors/event-schema-malformed';
    public const EVENT_SCHEMA_NOT_OBJECT = 'https://docs.eventsourcingdb.io/errors/event-schema-not-object';
    public const EVENT_SCHEMA_ALREADY_REGISTERED = 'https://docs.eventsourcingdb.io/errors/event-schema-already-registered';
    public const LAST_AUDITED_EVENT_MISSING = 'https://docs.eventsourcingdb.io/errors/last-audited-event-missing';
    public const LAST_AUDITED_EVENT_MISMATCHED = 'https://docs.eventsourcingdb.io/errors/last-audited-event-mismatched';
    public const LAST_AUDITED_EVENT_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/last-audited-event-unknown';
    public const EVENT_HASH_MISSING = 'https://docs.eventsourcingdb.io/errors/event-hash-missing';
    public const EVENT_HASH_MALFORMED = 'https://docs.eventsourcingdb.io/errors/event-hash-malformed';
    public const EVENT_CHAIN_BROKEN = 'https://docs.eventsourcingdb.io/errors/event-chain-broken';
    public const LAST_BACKED_UP_EVENT_MISSING = 'https://docs.eventsourcingdb.io/errors/last-backed-up-event-missing';
    public const LAST_BACKED_UP_EVENT_MISMATCHED = 'https://docs.eventsourcingdb.io/errors/last-backed-up-event-mismatched';
    public const LAST_BACKED_UP_EVENT_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/last-backed-up-event-unknown';
    public const STORE_NOT_EMPTY = 'https://docs.eventsourcingdb.io/errors/store-not-empty';
    public const EVENT_HASH_VERSION_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/event-hash-version-unknown';
    public const BACKUP_MALFORMED = 'https://docs.eventsourcingdb.io/errors/backup-malformed';
    public const BACKUP_INCOMPLETE = 'https://docs.eventsourcingdb.io/errors/backup-incomplete';
    public const BACKUP_FORMAT_UNKNOWN = 'https://docs.eventsourcingdb.io/errors/backup-format-unknown';
    public const FULL_BACKUP_MISSING = 'https://docs.eventsourcingdb.io/errors/full-backup-missing';
    public const BACKUP_INCREMENT_DISCONNECTED = 'https://docs.eventsourcingdb.io/errors/backup-increment-disconnected';
    public const BACKUP_FAILED = 'https://docs.eventsourcingdb.io/errors/backup-failed';
    public const BACKUP_UNREADABLE = 'https://docs.eventsourcingdb.io/errors/backup-unreadable';
    public const EVENT_TOO_LARGE = 'https://docs.eventsourcingdb.io/errors/event-too-large';
    public const DATA_MALFORMED = 'https://docs.eventsourcingdb.io/errors/data-malformed';
    public const QUERY_MISSING = 'https://docs.eventsourcingdb.io/errors/query-missing';
    public const QUERY_MALFORMED = 'https://docs.eventsourcingdb.io/errors/query-malformed';
    public const QUERY_UNEVALUABLE = 'https://docs.eventsourcingdb.io/errors/query-unevaluable';
    public const QUERY_NOT_BOOLEAN = 'https://docs.eventsourcingdb.io/errors/query-not-boolean';
    public const PRECONDITION_VIOLATED = 'https://docs.eventsourcingdb.io/errors/precondition-violated';
    public const STORE_READ_ONLY = 'https://docs.eventsourcingdb.io/errors/store-read-only';
    public const READ_ONLY_MODE_ALREADY_ENABLED = 'https://docs.eventsourcingdb.io/errors/read-only-mode-already-enabled';
    public const DISK_FULL = 'https://docs.eventsourcingdb.io/errors/disk-full';
    public const RESTORE_IN_PROGRESS = 'https://docs.eventsourcingdb.io/errors/restore-in-progress';
    public const STORE_FAILED = 'https://docs.eventsourcingdb.io/errors/store-failed';
    public const INDEXES_REBUILDING = 'https://docs.eventsourcingdb.io/errors/indexes-rebuilding';
    public const SERVER_SHUTTING_DOWN = 'https://docs.eventsourcingdb.io/errors/server-shutting-down';

    private function __construct()
    {
    }
}
