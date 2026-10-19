<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use InvalidArgumentException;
use Throwable;

/**
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\HttpClientTest
 */
class HttpClient
{
    private const SUPPORTED_METHODS = ['GET', 'POST', 'QUERY'];
    private const SUPPORTED_CONTENT_TYPES = ['application/json', 'application/x-ndjson', 'text/plain'];
    private CurlMultiHandler $curlMultiHandler;
    private bool $hasLastRequestEstablishedConnection = false;
    private ?int $lastStatusCode = null;

    public function __construct(
        private readonly ?string $baseUrl = null,
        float $stallTimeout = CurlMultiHandler::DEFAULT_STALL_TIMEOUT,
    ) {
        $this->curlMultiHandler = new CurlMultiHandler($stallTimeout);
    }

    public function abortIn(float $seconds): void
    {
        $this->curlMultiHandler->abortIn($seconds);
    }

    public function buildUri(string $uri): string
    {
        $buildUri = $this->baseUrl !== null ? rtrim($this->baseUrl, '/') . '/' : '';
        $buildUri .= ltrim($uri, '/');

        return $buildUri;
    }

    public function buildHeaders(?string $apiToken, null|array|object $body = null): array
    {
        $headers = ['Expect:'];
        if ($apiToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $apiToken;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    public function buildBody(null|array|object $body): string
    {
        if ($body === null) {
            return '';
        }

        $json = json_encode($body);
        if ($json === false) {
            throw new InvalidArgumentException('Internal HttpClient: Failed to encode body to JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Tells whether the last request got as far as a connection to the
     * server. If it failed before, nothing of it was sent, so a write that
     * failed that way was certainly not stored.
     */
    public function hasLastRequestEstablishedConnection(): bool
    {
        return $this->hasLastRequestEstablishedConnection;
    }

    /**
     * Returns the status with which the server answered the last request, or
     * null if it did not answer. It is known also if the request failed once
     * the answer arrived, such as for a content type that is not supported.
     *
     * @internal
     */
    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    /**
     * Tells whether the stream that was read last ended because the time that
     * abortIn set was over, rather than because the server ended it.
     */
    public function hasLastStreamBeenAborted(): bool
    {
        return $this->curlMultiHandler->hasLastStreamBeenAborted();
    }

    public function get(string $uri, ?string $apiToken = null): Response
    {
        $this->hasLastRequestEstablishedConnection = false;
        $this->lastStatusCode = null;

        $request = new Request(
            'GET',
            $this->buildUri($uri),
            $this->buildHeaders($apiToken),
        );

        return $this->sendRequest($request);
    }

    public function post(string $uri, ?string $apiToken = null, null|array|object $body = null): Response
    {
        $this->hasLastRequestEstablishedConnection = false;
        $this->lastStatusCode = null;

        $request = new Request(
            'POST',
            $this->buildUri($uri),
            $this->buildHeaders($apiToken, $body),
            $this->buildBody($body),
        );

        return $this->sendRequest($request);
    }

    /**
     * Sends a request with the method QUERY (RFC 10008), which reads like GET
     * does, but carries a body like POST.
     */
    public function query(string $uri, ?string $apiToken = null, null|array|object $body = null): Response
    {
        $this->hasLastRequestEstablishedConnection = false;
        $this->lastStatusCode = null;

        $request = new Request(
            'QUERY',
            $this->buildUri($uri),
            $this->buildHeaders($apiToken, $body),
            $this->buildBody($body),
        );

        return $this->sendRequest($request);
    }

    public function sendRequest(Request $request): Response
    {
        $this->hasLastRequestEstablishedConnection = false;
        $this->lastStatusCode = null;

        if (!in_array($request->getMethod(), self::SUPPORTED_METHODS, true)) {
            throw new InvalidArgumentException("Internal HttpClient: got Request Method '{$request->getMethod()}', expected one of: " .
                implode(', ', self::SUPPORTED_METHODS) . '.');
        }

        $this->curlMultiHandler->addHandle($request);

        // A response that is refused is never read, so its connection is
        // closed here rather than kept open until the next request.
        try {
            $this->curlMultiHandler->execute();

            $headerQueue = $this->curlMultiHandler->getHeaderQueue();
            $responseHeader = $this->parseHeaderQueue($headerQueue);
            $this->lastStatusCode = $responseHeader->statusCode;

            if (!$this->isContentTypeSupported($responseHeader->contentType)) {
                throw new InvalidArgumentException(
                    "Internal HttpClient: got Content-Type '{$responseHeader->contentType}', expected one of: " .
                    implode(', ', self::SUPPORTED_CONTENT_TYPES),
                );
            }

            $response = new Response(
                statusCode: $responseHeader->statusCode,
                headers: iterator_to_array($headerQueue),
                stream: new Stream($this->curlMultiHandler),
                protocolVersion: $responseHeader->httpVersion,
            );
        } catch (Throwable $throwable) {
            $this->hasLastRequestEstablishedConnection = $this->curlMultiHandler->hasEstablishedConnection();
            $this->curlMultiHandler->close();

            throw $throwable;
        }

        $this->hasLastRequestEstablishedConnection = true;

        return $response;
    }

    public function parseHeaderQueue(Queue $queue): Header
    {
        foreach ($queue as $line) {
            if (preg_match('/^HTTP\/(\d\.\d)\s+(\d{3})/i', $line, $matches)) {
                $httpVersion = trim($matches[1]);
                $httpStatus = (int) $matches[2];
            }
            if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $matches)) {
                $contentType = trim($matches[1]);
            }
            if (preg_match('/^Content-Length:\s*(\d+)$/i', $line, $matches)) {
                $contentLength = (int) $matches[1];
            }
        }

        return new Header(
            $httpStatus ?? 418,
            $httpVersion ?? '1.1',
            $contentType ?? 'text/plain',
            $contentLength ?? 0,
        );
    }

    public function isContentTypeSupported(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        foreach (self::SUPPORTED_CONTENT_TYPES as $supportedType) {
            if (str_starts_with($contentType, $supportedType)) {
                return true;
            }
        }

        return false;
    }
}
