<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use RuntimeException;
use Throwable;

/**
 * An error that EventSourcingDB reports, be it one of the request, such as a
 * precondition that does not hold, or one of the server, such as a request
 * that is not authorized.
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\DbApiExceptionTest
 */
final class DbApiException extends RuntimeException
{
    /**
     * @param string $errorCode the code of the error exactly as the server sends it, which is the URL of the page that documents it, such as ErrorCode::PRECONDITION_VIOLATED, or an empty string for an error without a code
     * @param string $description the text that describes the error
     * @param array $details the details of the error, as the v2 API sends them, or an empty array
     * @param int $status the HTTP status of the response, which is 200 for an error that the v2 API reports in the body of a response
     * @param int|null $retryAfter for a response with the status 429, the seconds after which the request may be repeated, if the server names them
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $description,
        public readonly array $details,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
