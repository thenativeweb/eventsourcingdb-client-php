<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Closure;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Throwable;

/**
 * Tells a write that failed, of events or of an event schema, and was
 * certainly not stored, from one that may have been stored nonetheless,
 * which it reports as an OutcomeUnknownException. A write was certainly not
 * stored only if the client refused it before sending it, such as a write
 * with a recursive precondition to a server without the v2 API, which happens
 * before this guard, if the connection to the server could not be established
 * at all, or if the server refused it explicitly. Everything else, such as a
 * stall, a broken connection, or an answer that does not come from
 * EventSourcingDB, leaves the outcome open, since EventSourcingDB may have
 * stored the write before its answer was lost.
 *
 * @internal
 */
final class WriteOutcome
{
    /**
     * @template T
     *
     * @param Closure(): T $write the write, which sends exactly one request with the given HTTP client
     * @param Closure(DbApiException): bool $isRefusal tells whether an error the server reports means that it refused the write
     * @param string $unknownOutcome the message that says what may have been stored nonetheless
     *
     * @return T
     */
    public static function guard(HttpClient $httpClient, Closure $write, Closure $isRefusal, string $unknownOutcome): mixed
    {
        try {
            return $write();
        } catch (DbApiException $dbApiException) {
            if ($isRefusal($dbApiException)) {
                throw $dbApiException;
            }

            throw new OutcomeUnknownException("{$unknownOutcome}: {$dbApiException->getMessage()}", $dbApiException);
        } catch (Throwable $throwable) {
            if (!$httpClient->hasLastRequestEstablishedConnection()) {
                throw $throwable;
            }

            throw new OutcomeUnknownException("{$unknownOutcome}: {$throwable->getMessage()}", $throwable);
        }
    }
}
