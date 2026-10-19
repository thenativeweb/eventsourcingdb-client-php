<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use RuntimeException;
use Throwable;

/**
 * A write, of events or of an event schema, that failed in a way that leaves
 * open whether it was stored nonetheless, e.g. because the connection broke
 * after the request was sent. EventSourcingDB never stores a write halfway,
 * so it was either stored completely or not at all. The cause is the previous
 * exception.
 */
final class OutcomeUnknownException extends RuntimeException
{
    public function __construct(string $message, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
