<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use RuntimeException;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;

/**
 * Chooses the API to speak with a server, on the first request of a client,
 * and remembers it for the client. It asks the server for the ping of the v2
 * API: a server that answers it offers the v2 API, and one that does not know
 * the route, which it answers with 404 or 405, offers the v1 API only. Any
 * other outcome, such as a failed connection or a 503, fails the request that
 * asked, and leaves the choice open, so that the next request asks again.
 *
 * This is the one place that chooses between the APIs, so that removing the
 * v1 API means removing V1Transport, V1ErrorCodes, and its choice here.
 *
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Api\TransportSelectorTest
 */
final class TransportSelector
{
    public const PROBE_PATH = '/api/v2/system/ping';

    private ?Transport $transport = null;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiToken,
    ) {
    }

    public function getTransport(): Transport
    {
        // PHP runs one request at a time, so the first request of a client
        // finishes asking before any other one starts, and the others use
        // the answer it remembered.
        return $this->transport ??= $this->detectTransport();
    }

    private function detectTransport(): Transport
    {
        // The client asks on its own behalf, so an abort time that the caller
        // set applies to the request that the caller made, not to this one.
        $response = $this->httpClient->get(self::PROBE_PATH, takesAbortTime: false);

        Responses::throwIfNotFromEventSourcingDb($response);

        $status = $response->getStatusCode();
        if ($status === 404 || $status === 405) {
            // The answer is not needed, so its connection is closed rather than
            // kept open until the next request.
            $response->getStream()
                ->close();

            return new V1Transport($this->httpClient, $this->apiToken);
        }

        $scope = 'Failed to find out the API of the server';

        if ($status !== 200) {
            throw Responses::createErrorFromStatus($response, $scope);
        }

        try {
            $item = $response->getStream()
                ->getJsonData();
        } catch (ConnectionStalledException $connectionStalledException) {
            throw $connectionStalledException;
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

        return new V2Transport($this->httpClient, $this->apiToken);
    }
}
