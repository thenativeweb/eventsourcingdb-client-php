<?php

namespace Thenativeweb\Eventsourcingdb;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class Client
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(string $url, string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient([
            'base_uri' => rtrim($url, '/'),
            'http_errors' => false
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

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.ping-received') {
            throw new RuntimeException('Failed to ping');
        }
    }

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

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!isset($data['type']) || $data['type'] !== 'io.eventsourcingdb.api.api-token-verified') {
            throw new RuntimeException('Failed to verify API token');
        }
    }
}
