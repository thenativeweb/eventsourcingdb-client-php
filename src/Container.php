<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Exception;
use RuntimeException;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Thenativeweb\Eventsourcingdb\Stream\HttpClient;

final class Container
{
    private string $imageName = 'thenativeweb/eventsourcingdb';
    private string $imageTag = 'latest';
    private int $internalPort = 3000;
    private string $apiToken = 'secret';
    private ?StartedGenericContainer $container = null;
    private HttpClient $httpClient;

    public function __construct()
    {
        $this->httpClient = new HttpClient();
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

    /**
     * @throws Exception
     */
    public function start(): void
    {
        $container = null;
        $retryCount = 5;
        while ($retryCount) {
            try {
                $container =
                    (new GenericContainer("{$this->imageName}:{$this->imageTag}"))
                        ->withExposedPorts($this->internalPort)
                        ->withCommand([
                            'run',
                            '--api-token', $this->apiToken,
                            '--data-directory-temporary',
                            '--http-enabled',
                            '--https-enabled=false',
                        ]);

            } catch (Exception $exception) {
                --$retryCount;

                $exceptionMessage = $exception->getMessage();

                sleep(6);
            }

            if ($container instanceof GenericContainer) {
                break;
            }
        }

        if (!$container instanceof GenericContainer) {
            exit($exceptionMessage ?? 'Failed to create container');
        }

        $this->container = $container->start();

        $baseUrl = rtrim($this->getBaseUrl());
        $pingUrl = $baseUrl . '/api/v1/ping';

        while (true) {
            try {
                $response = $this->httpClient->get($pingUrl);
            } catch (Exception) {
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
        return $this->container instanceof StartedGenericContainer;
    }

    public function stop(): void
    {
        if ($this->container instanceof StartedGenericContainer) {
            $this->container->stop();
            $this->container = null;
        }
    }

    public function getClient(): Client
    {
        $baseUrl = $this->getBaseUrl();
        return new Client($baseUrl, $this->apiToken);
    }

    private function ensureRunning(): void
    {
        if (!$this->container instanceof StartedGenericContainer) {
            throw new RuntimeException('Container must be running');
        }
    }
}
