<?php

namespace Thenativeweb\Eventsourcingdb;

use Symfony\Component\Process\Process;
use Testcontainers\Container\GenericContainer;
use Testcontainers\DockerClient\DockerClient;
use Testcontainers\DockerClient\DockerClientFactory;
use Testcontainers\Wait\WaitForHttp;

class Container
{
    private string $imageName = 'thenativeweb/eventsourcingdb';
    private string $imageTag;
    private int $internalPort = 3000;
    private string $apiToken = 'secret';
    private ?GenericContainer $container = null;

    public function __construct()
    {
        $this->imageTag = $this->getImageVersionFromDockerfile();
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
        $this->container = new GenericContainer("{$this->imageName}:{$this->imageTag}");

        $this->container
            ->withExposedPorts($this->internalPort)
            ->withCommand([
                'run',
                '--api-token', $this->apiToken,
                '--data-directory-temporary',
                '--http-enabled',
                '--https-enabled=false'
            ])
            ->withWait(
                new WaitForHttp($this->internalPort)
                    ->withMethod('GET')
                    ->withPath('/api/v1/ping')
            );

        $this->container->start();
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

    private function getImageVersionFromDockerfile(): string
    {
        $dockerfile = __DIR__ . '/../docker/Dockerfile';

        if (!file_exists($dockerfile)) {
            throw new \RuntimeException('Dockerfile not found at ' . $dockerfile);
        }

        $content = file_get_contents($dockerfile);
        if (!preg_match('/^FROM\s+thenativeweb\/eventsourcingdb:(.+)$/m', $content, $matches)) {
            throw new \RuntimeException('Failed to extract image version from Dockerfile');
        }

        return trim($matches[1]);
    }
}
