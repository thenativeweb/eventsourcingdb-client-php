<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use RuntimeException;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;
use Throwable;

/**
 * Chooses the API to speak with a server, on the first request of a client,
 * and remembers it for the client. It asks the server for the ping of the v2
 * API: a server that answers it offers the v2 API, and one that does not know
 * the route, which it answers with 404 or 405, offers the v1 API only. Any
 * other outcome, such as a failed connection or a 503, fails the request that
 * asked, and leaves the choice as it was: without one, the next request asks
 * again. Once the server answers a route of the v2 API with 404 or 405, e.g.
 * since it was rolled back to a version without it, the client forgets the
 * choice, and the next request asks again, too. A request that needs the v2
 * API, such as a write with a recursive precondition, asks again as well, if
 * the client remembers that the server offers the v1 API only, since the
 * server may offer the v2 API by now, e.g. after an upgrade. If that asking
 * fails, the client keeps the v1 API, and every other request goes on using
 * it without asking.
 *
 * This is the one place that chooses between the APIs, so that removing the
 * v1 API means removing V1Transport, V1ErrorCodes, Preconditions, its choice
 * here, including asking again for a request that needs the v2 API, the
 * argument isV2ApiNeeded that Client::writeEvents passes for that, and the
 * ping of the v1 API that Container asks where meansNoV2Api says so.
 *
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Api\TransportSelectorTest
 */
final class TransportSelector
{
    public const PROBE_PATH = '/api/v2/system/ping';

    private ?Transport $transport = null;

    /**
     * Tells whether the given status, with which a server answered a route of
     * the v2 API, means that the server does not offer the v2 API, as a
     * server without it answers a route that it does not know with 404 or
     * 405. A missing status, of a request that got no answer, says nothing
     * about the API.
     */
    public static function meansNoV2Api(?int $status): bool
    {
        return $status === 404 || $status === 405;
    }

    /**
     * @param HttpClient $httpClient the HTTP client of the requests that the caller makes
     * @param ProbingHttpClient $probingHttpClient an HTTP client of its own to ask the server with, so that asking does not take over the abort time that the caller set for the request that the caller made, but ends at it, and that accepts an answer of any content type
     */
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiToken,
        private readonly ProbingHttpClient $probingHttpClient,
    ) {
    }

    /**
     * @param bool $isV2ApiNeeded whether the request needs the v2 API, such as a write with a recursive precondition
     */
    public function getTransport(bool $isV2ApiNeeded = false): Transport
    {
        $isAskingAgain = $isV2ApiNeeded && $this->transport instanceof V1Transport;
        if ($this->transport instanceof Transport && !$isAskingAgain) {
            return $this->transport;
        }

        // PHP runs one request at a time, so the first request of a client
        // finishes asking before any other one starts, and the others use
        // the answer it remembered. A stream that is being read while a
        // request asks again keeps the transport that it started with. Only
        // an answer replaces the one the client remembers, so asking that
        // fails leaves it as it was.
        try {
            // Asking is a part of the request that the caller made, so it ends
            // at the abort time that the caller set for that request, and
            // leaves the rest of it to the request. An abort time of a stream
            // being read, which a request that asks again within its loop
            // meets, stays with the stream.
            $this->probingHttpClient->abortAt($this->httpClient->getAbortDeadlineOfNextRequest());

            return $this->transport = $this->detectTransport();
        } catch (Throwable $throwable) {
            // The request that asked fails, so it uses up an abort time that
            // the caller set for it, as a request that fails does, rather than
            // leave it to a later request. An abort time of a stream being
            // read, which a request that asks again within its loop meets,
            // stays with the stream.
            $this->httpClient->useUpAbortTimeOfNextRequest();

            throw $throwable;
        }
    }

    private function detectTransport(): Transport
    {
        $response = $this->probingHttpClient->get(self::PROBE_PATH);

        // A server that does not know the route offers the v1 API only, also if
        // the answer does not come from EventSourcingDB itself, but from a proxy
        // in front of it that passes on only the routes of the v1 API, whatever
        // the content type or the body of its answer. Every request through the
        // v1 API checks that EventSourcingDB answers it.
        $status = $response->getStatusCode();
        if (self::meansNoV2Api($status)) {
            // The answer is not needed, so its connection is closed rather than
            // kept open until the next request.
            $response->getStream()
                ->close();

            return new V1Transport($this->httpClient, $this->apiToken);
        }

        Responses::throwIfNotFromEventSourcingDb($response);

        $scope = 'Failed to find out the API of the server';

        if ($status !== 200) {
            throw Responses::createErrorFromStatus($response, $scope);
        }

        try {
            $item = $response->getStream()
                ->getJsonData();
        } catch (ConnectionStalledException|RequestAbortedException $exception) {
            throw $exception;
        } catch (RuntimeException $runtimeException) {
            throw new RuntimeException(
                "{$scope}: " . $runtimeException->getMessage(),
                $runtimeException->getCode(),
                $runtimeException,
            );
        }

        $isPingOfV2 = ($item['type'] ?? null) === 'event'
            && is_array($item['data'] ?? null)
            && ($item['data']['type'] ?? null) === 'io.eventsourcingdb.api.ping-received';

        if (!$isPingOfV2) {
            throw new RuntimeException("{$scope}, since it answered the ping of the v2 API with something other than a ping.");
        }

        return new V2Transport($this->httpClient, $this->apiToken, $this->forget(...));
    }

    /**
     * Forgets the API, so that the next request asks again, if the client
     * still remembers the given transport. An answer through a transport that
     * the client has forgotten already thus never discards the transport that
     * asking again chose since.
     */
    private function forget(Transport $transport): void
    {
        if ($this->transport === $transport) {
            $this->transport = null;
        }
    }
}
