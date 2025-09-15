<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Exception;
use RuntimeException;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Thenativeweb\Eventsourcingdb\TestContainer\WaitForHttp;

final class Container
{
    private string $imageName = 'thenativeweb/eventsourcingdb';
    private string $imageTag = 'latest';
    private int $internalPort = 3000;
    private string $apiToken = 'secret';
    private ?SigningKey $signingKey = null;
    private ?StartedGenericContainer $container = null;

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

    public function withSigningKey(): self
    {
        $this->signingKey = new SigningKey();

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
        $command = [
            'run',
            '--api-token',
            $this->apiToken,
            '--data-directory-temporary',
            '--http-enabled',
            '--https-enabled=false',
        ];
        $content = [];

        if ($this->signingKey instanceof SigningKey) {
            $command[] = '--signing-key-file';
            $command[] = '/etc/esdb/signing-key.pem';

            $content[] = [
                'content' => $this->signingKey->privateKeyPem,
                'target' => '/etc/esdb/signing-key.pem',
                'mode' => 0o777,
            ];
        }

        $container =
            (new GenericContainer("{$this->imageName}:{$this->imageTag}"))
                ->withExposedPorts($this->internalPort)
                ->withCommand($command)
                ->withCopyContentToContainer($content)
                ->withWait((new WaitForHttp($this->internalPort, 20000))->withPath('/api/v1/ping'))
        ;

        try {
            $this->container = $container->start();
        } catch (Exception) {
            usleep(100_000);
            $this->container = $container->start();
        }
    }

    public function getHost(): string
    {
        $startedGenericContainer = $this->runningContainer();
        return $startedGenericContainer->getHost();
    }

    public function getMappedPort(): int
    {
        $startedGenericContainer = $this->runningContainer();
        return $startedGenericContainer->getMappedPort($this->internalPort);
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

    public function getSigningKey(): SigningKey
    {
        if (!$this->signingKey instanceof SigningKey) {
            throw new RuntimeException('Signing key not set.');
        }

        return $this->signingKey;
    }

    public function getVerificationKey(): string
    {
        if (!$this->signingKey instanceof SigningKey) {
            throw new RuntimeException('Signing key not set.');
        }

        return $this->signingKey->ed25519->publicKey;
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

    private function runningContainer(): StartedGenericContainer
    {
        if (!$this->container instanceof StartedGenericContainer) {
            throw new RuntimeException('Container must be running');
        }

        return $this->container;
    }
}
