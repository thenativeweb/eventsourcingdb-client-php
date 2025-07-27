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
        private readonly ?string $baseUrl = null,
    ) {
        $this->curlMultiHandler = new CurlMultiHandler();
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
        $header = [];
        if ($apiToken !== null) {
            $header[] = 'Authorization: Bearer ' . $apiToken;
        }
        if ($body !== null && !$body instanceof FileUpload) {
            $header[] = 'Content-Type: application/json';
        }
        if ($body instanceof FileUpload) {
            $header[] = 'Content-Type: ' . $body->getContentType();
        }

        return $header;
    }

    public function buildBody(null|array|object $file): string|FileUpload
    {
        if ($file === null) {
            return '';
        }

        if ($file instanceof FileUpload) {
            if (!$file->isReadable()) {
                throw new InvalidArgumentException('Internal HttpClient: SplFileObject must be readable.');
            }

            return $file;
        }

        return json_encode($file);
    }

    public function get(string $uri, ?string $apiToken = null): Response
    {
        $request = new Request(
            'GET',
            $this->buildUri($uri),
            $this->buildHeaders($apiToken),
        );

        return $this->sendRequest($request);
    }

    public function post(string $uri, ?string $apiToken = null, null|array|object $body = null): Response
    {
        $request = new Request(
            'POST',
            $this->buildUri($uri),
            $this->buildHeaders($apiToken, $body),
            $this->buildBody($body),
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
