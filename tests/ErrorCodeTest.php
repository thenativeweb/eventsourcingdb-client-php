<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Thenativeweb\Eventsourcingdb\ErrorCode;

final class ErrorCodeTest extends TestCase
{
    /**
     * The slugs of the errors that EventSourcingDB reports, in the order of
     * cmd/server/api/error.go on the main branch of its repository, from
     * which they are copied.
     */
    private const SLUGS_OF_THE_SERVER = [
        'subject-missing',
        'subject-malformed',
        'order-unknown',
        'event-id-missing',
        'event-id-malformed',
        'bound-type-missing',
        'bound-type-unknown',
        'range-unsatisfiable',
        'from-latest-event-incomplete',
        'if-event-is-missing-unknown',
        'selection-conflicting',
        'event-type-missing',
        'event-type-malformed',
        'event-type-unknown',
        'source-missing',
        'source-malformed',
        'data-missing',
        'traceparent-malformed',
        'tracestate-malformed',
        'tracestate-without-traceparent',
        'events-missing',
        'precondition-type-missing',
        'precondition-unknown',
        'payload-missing',
        'license-invalid',
        'capacity-exceeded',
        'event-schema-violated',
        'event-schema-unregistered',
        'event-schema-missing',
        'event-schema-malformed',
        'event-schema-not-object',
        'event-schema-already-registered',
        'last-audited-event-missing',
        'last-audited-event-mismatched',
        'last-audited-event-unknown',
        'event-hash-missing',
        'event-hash-malformed',
        'event-chain-broken',
        'last-backed-up-event-missing',
        'last-backed-up-event-mismatched',
        'last-backed-up-event-unknown',
        'store-not-empty',
        'event-hash-version-unknown',
        'backup-malformed',
        'backup-incomplete',
        'backup-format-unknown',
        'full-backup-missing',
        'backup-increment-disconnected',
        'backup-failed',
        'backup-unreadable',
        'event-too-large',
        'data-malformed',
        'query-missing',
        'query-malformed',
        'query-unevaluable',
        'query-not-boolean',
        'precondition-violated',
        'store-read-only',
        'read-only-mode-already-enabled',
        'disk-full',
        'restore-in-progress',
        'store-failed',
        'indexes-rebuilding',
        'server-shutting-down',
    ];

    public function testHasAConstantForEveryCodeOfTheServer(): void
    {
        $expected = [];
        foreach (self::SLUGS_OF_THE_SERVER as $slug) {
            $name = strtoupper(str_replace('-', '_', $slug));
            $expected[$name] = "https://docs.eventsourcingdb.io/errors/{$slug}";
        }

        $constants = (new ReflectionClass(ErrorCode::class))->getConstants();

        $this->assertSame($expected, $constants);
    }

    public function testNamesThePreconditionThatDoesNotHold(): void
    {
        $this->assertSame('https://docs.eventsourcingdb.io/errors/precondition-violated', ErrorCode::PRECONDITION_VIOLATED);
    }
}
