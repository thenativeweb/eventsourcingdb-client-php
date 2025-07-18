<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;

final readonly class Client
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(string $url, string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient([
            'base_uri' => rtrim($url, '/'),
            'http_errors' => false,
        ]);
    }

    public function ping(): void
    {
        $response = $this->httpClient->get('/api/v1/ping');
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to ping, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.ping-received') {
            throw new RuntimeException('Failed to ping');
        }
    }

    /**
     * @throws GuzzleException
     */
    public function verifyApiToken(): void
    {
        $response = $this->httpClient->post(
            '/api/v1/verify-api-token',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to verify API token, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }

    /**
     * @return CloudEvent[]
     * @throws Exception|GuzzleException
     */
    public function writeEvents(array $events, array $preconditions = []): iterable
    {
        $requestBody = [
            'events' => $events,
        ];
        if ($preconditions !== []) {
            $requestBody['preconditions'] = $preconditions;
        }

        $response = $this->httpClient->post(
            '/api/v1/write-events',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to write events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $body = $response->getBody()->getContents();
        if ($body === '') {
            return;
        }

        if (!json_validate($body)) {
            throw new RuntimeException('Failed to read events.');
        }

        $data = json_decode($body, true);
        foreach ($data as $item) {
            yield new CloudEvent(
                $item['specversion'],
                $item['id'],
                new DateTimeImmutable($item['time']),
                $item['source'],
                $item['subject'],
                $item['type'],
                $item['datacontenttype'],
                $item['data'],
                $item['hash'],
                $item['predecessorhash'],
                $item['traceparent'] ?? null,
                $item['tracestate'] ?? null,
            );
        }
    }

    /**
     * @return CloudEvent[]
     * @throws Exception|GuzzleException
     */
    public function readEvents(string $subject, ReadEventsOptions $readEventsOptions): iterable
    {
        $requestBody = [
            'subject' => $subject,
            'options' => $readEventsOptions,
        ];

        $response = $this->httpClient->post(
            '/api/v1/read-events',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
            ],
        );
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf(
                "Failed to read events, got HTTP status code '%d', expected '200'",
                $status
            ));
        }

        $responseStream = $response->getBody();
        while (!$responseStream->eof()) {
            $line = Utils::readLine($responseStream);
            if ($line === '') {
                continue;
            }

            if (!json_validate($line)) {
                throw new RuntimeException('Failed to read events.');
            }

            $item = json_decode($line, true);

            $payload = $item['payload'] ?? null;
            if ($payload === null) {
                throw new RuntimeException('Payload is missing in the event data.');
            }

            yield new CloudEvent(
                $payload['specversion'],
                $payload['id'],
                new DateTimeImmutable($payload['time']),
                $payload['source'],
                $payload['subject'],
                $payload['type'],
                $payload['datacontenttype'],
                $payload['data'],
                $payload['hash'],
                $payload['predecessorhash'],
                $payload['traceparent'] ?? null,
                $payload['tracestate'] ?? null,
            );
        }
    }
}
