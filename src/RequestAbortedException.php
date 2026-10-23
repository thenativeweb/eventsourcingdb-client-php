<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use RuntimeException;

/**
 * A request that ended because the time that abortIn set was over, before
 * its answer arrived, or while it was read. A stream that is aborted ends
 * without it, and a write that is aborted once the connection to the server
 * is established throws an OutcomeUnknownException whose previous exception
 * it is, since the server may have stored the write nonetheless.
 */
final class RequestAbortedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The request was aborted, since the time that abortIn set was over.');
    }
}
