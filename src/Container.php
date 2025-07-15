<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;

final class Container
{
    private string $imageName;
    private string $imageTag;
    private int $internalPort;
    private string $apiToken;
    private ?StartedGenericContainer $container;
    private HttpClient $httpClient;

    public function __construct()
    {
        $this->imageName = 'thenativeweb/eventsourcingdb';
        $this->imageTag = 'latest';
        $this->internalPort = 3000;
        $this->apiToken = 'secret';
        $this->container = null;
        $this->httpClient = new HttpClient([
            'http_errors' => false
        ]);
    }

    public function withImageTag(string $tag): self
    {
        $this->imageTag = $tag;
        return $this;
    }

    public function withApiToken(string $token): self
    {
        $this->apiToken = $token;
        return $this;
    }

    public function withPort(int $port): self
    {
        $this->internalPort = $port;
        return $this;
    }

    public function start(): void
    {
        $container =
            (new GenericContainer("{$this->imageName}:{$this->imageTag}"))
                ->withExposedPorts($this->internalPort)
                ->withCommand([
                    'run',
                    '--api-token', $this->apiToken,
                    '--data-directory-temporary',
                    '--http-enabled',
                    '--https-enabled=false'
                ]);

        $this->container = $container->start();

        $baseUrl = rtrim($this->getBaseUrl());
        $pingUrl = $baseUrl . '/api/v1/ping';

        while (true) {
            try {
                $response = $this->httpClient->get($pingUrl);
            } catch (GuzzleException $e) {
                usleep(100_000);
                continue;
            }
            $status = $response->getStatusCode();

            if ($status === 200) {
                break;
            }
        }
    }

    public function getHost(): string
    {
        $this->ensureRunning();

        if ($_ENV['APP_ENV'] === 'test-docker') {
            return 'host.docker.internal';
        }

        return $this->container->getHost();
    }

    public function getMappedPort(): int
    {
        $this->ensureRunning();
        return $this->container->getMappedPort($this->internalPort);
    }

    public function getBaseUrl(): string
    {
        $host = $this->getHost();
        $port = $this->getMappedPort();
        return "http://{$host}:{$port}";
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function isRunning(): bool
    {
        return $this->container !== null;
    }

    public function stop(): void
    {
        if ($this->container !== null) {
            $this->container->stop();
            $this->container = null;
        }
    }

    public function getClient(): \Thenativeweb\Eventsourcingdb\Client
    {
        $baseUrl = $this->getBaseUrl();
        return new \Thenativeweb\Eventsourcingdb\Client($baseUrl, $this->apiToken);
    }

    private function ensureRunning(): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container must be running');
        }
    }
}
