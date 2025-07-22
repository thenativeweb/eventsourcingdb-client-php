<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use InvalidArgumentException;

class HttpClient
{
    private const SUPPORTED_METHODS = ['GET', 'POST'];
    private const SUPPORTED_CONTENT_TYPES = ['application/json', 'application/x-ndjson', 'text/plain'];
    private CurlMultiHandler $curlMultiHandler;

    public function __construct(
        private ?string $baseUrl = null,
    ) {
        $this->curlMultiHandler = new CurlMultiHandler();
    }

    public function cancelStreamAfter(float $time): void
    {
        $this->curlMultiHandler->cancelStreamAfter($time);
    }

    public function buildUri(string $uri): string
    {
        $buildUri = $this->baseUrl !== null ? rtrim($this->baseUrl, '/') . '/' : '';
        $buildUri .= ltrim($uri, '/');

        return $buildUri;
    }

    public function get(string $uri, ?string $apiToken = null): Response
    {
        $header = $apiToken !== null ? ['Authorization: Bearer ' . $apiToken] : [];

        $request = new Request(
            'GET',
            $this->buildUri($uri),
            $header,
        );

        return $this->sendRequest($request);
    }

    public function post(string $uri, ?string $apiToken = null, array $jsonArray = []): Response
    {
        $header = [];
        if ($apiToken !== null) {
            $header[] = 'Authorization: Bearer ' . $apiToken;
        }
        if ($jsonArray !== []) {
            $header[] = 'Content-Type: application/json';
        }

        $request = new Request(
            'POST',
            $this->buildUri($uri),
            $header,
            json_encode($jsonArray),
        );

        return $this->sendRequest($request);
    }

    public function sendRequest(Request $request): Response
    {
        if (!in_array($request->getMethod(), self::SUPPORTED_METHODS, true)) {
            throw new InvalidArgumentException("Internal HttpClient: got Request Method '{$request->getMethod()}', expected one of: " .
                implode(', ', self::SUPPORTED_METHODS) . '.');
        }

        $this->curlMultiHandler->addHandle($request);
        $this->curlMultiHandler->execute();

        $headerQueue = $this->curlMultiHandler->getHeaderQueue();
        $responseHeader = $this->parseHeaderQueue($headerQueue);

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
