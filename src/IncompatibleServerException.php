<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use RuntimeException;

/**
 * A request that needs something the server does not offer, such as a
 * recursive precondition, which only the v2 API offers. The client throws it
 * only after asking the server which API it offers, also if it remembered the
 * answer, and before it sends the request, so a write that fails with it
 * wrote nothing.
 */
final class IncompatibleServerException extends RuntimeException
{
}
