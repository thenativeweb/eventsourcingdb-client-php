<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use Thenativeweb\Eventsourcingdb\Stream\HttpClient;

/**
 * The HTTP client with which TransportSelector asks the server which API it
 * offers. It accepts an answer of any content type, so that the status of
 * the answer decides first: a proxy in front of a database without the v2
 * API answers the route of the ping of the v2 API with a page of its own,
 * such as the HTML page of a 404 of nginx. TransportSelector checks the
 * answers of any other status itself.
 *
 * @internal
 */
final class ProbingHttpClient extends HttpClient
{
    public function isContentTypeSupported(string $contentType): bool
    {
        return true;
    }
}
