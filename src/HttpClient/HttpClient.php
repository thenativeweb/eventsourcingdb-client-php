<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class HttpClient
{
    private CurlMultiHandler $curlMultiHandler;

    public function __construct(
        private ?string $baseUrl = null,
    ) {
        $this->curlMultiHandler = new CurlMultiHandler();
    }

    public function abortStream(float $timeout): void
    {
        $this->curlMultiHandler->setStreamTimeout($timeout);
    }

    public function buildUri(string $uri): string
    {
        $buildUri = $this->baseUrl !== null ? rtrim($this->baseUrl, '/') . '/' : '';
        $buildUri .= ltrim($uri, '/');

        return $buildUri;
    }

    public function get(string $uri, ?string $apiToken = null): ResponseInterface
    {
        $header = $apiToken !== null ? ['Authorization: Bearer ' . $apiToken] : [];

        $request = new Request(
            'GET',
            $this->buildUri($uri),
            $header,
        );

        return $this->sendRequest($request);
    }

    public function post(string $uri, ?string $apiToken = null, array $jsonArray = []): ResponseInterface
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

    public function sendRequest(Request $request): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'POST'])) {
            throw new \InvalidArgumentException('Only GET and POST requests are supported.');
        }

        $this->curlMultiHandler->addHandle($request);
        $this->curlMultiHandler->execute();
        $headerQueue = $this->curlMultiHandler->header;
        $responseHeader = $this->parseHeaderQueue($headerQueue);

        $response = new Response(
            statusCode: $responseHeader->statusCode,
            headers: (array) $headerQueue,
            stream: new Stream($this->curlMultiHandler),
            protocolVersion: $responseHeader->httpVersion,
        );

        return $response;
    }

    public function parseHeaderQueue(BufferQueue $header): ResponseHeader
    {
        foreach ($header as $line) {
            if (preg_match('/^HTTP\/(\d\.\d)\s+(\d{3})/', $line, $matches)) {
                $httpVersion = $matches[1];
                $httpStatus = (int) $matches[2];
            }
        }

        return new ResponseHeader(
            $httpStatus ?? 418,
            $httpVersion ?? '1.1',
        );
    }
}
