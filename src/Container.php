<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Docker\API\Exception\ContainerDeleteConflictException;
use Docker\API\Exception\ContainerDeleteNotFoundException;
use Docker\API\Model\ContainerSummary;
use Exception;
use RuntimeException;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\ContainerClient\DockerContainerClient;
use Testcontainers\Wait\WaitForHttp;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\ContainerTest
 */
final class Container
{
    private const START_LABEL = 'io.eventsourcingdb.container-start';
    private const REMOVAL_TIMEOUT = 5;

    private string $imageName = 'thenativeweb/eventsourcingdb';
    private string $imageTag = 'latest';
    private int $internalPort = 3000;
    private string $apiToken = 'secret';
    private ?SigningKey $signingKey = null;
    private ?StartedGenericContainer $container = null;
    private ?string $tempSigningKeyFile = null;

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
            '--http-port',
            (string) $this->internalPort,
        ];

        if ($this->signingKey instanceof SigningKey) {
            $command[] = '--signing-key-file';
            $command[] = '/etc/esdb/signing-key.pem';
        }

        $startId = bin2hex(random_bytes(16));

        $container = (new GenericContainer("{$this->imageName}:{$this->imageTag}"))
            ->withExposedPorts($this->internalPort)
            ->withCommand($command)
            ->withLabels([
                self::START_LABEL => $startId,
            ]);

        if ($this->signingKey instanceof SigningKey) {
            $this->tempSigningKeyFile = getcwd() . '/.esdb_signing_key_' . uniqid();
            file_put_contents($this->tempSigningKeyFile, $this->signingKey->privateKeyPem);
            chmod($this->tempSigningKeyFile, 0o644);

            $container = $container->withMount($this->tempSigningKeyFile, '/etc/esdb/signing-key.pem');
        }

        $container = $container->withWait((new WaitForHttp($this->internalPort, 20000))->withPath('/api/v1/ping'));

        try {
            $this->container = $this->startContainer($container, $startId);
        } catch (Exception) {
            usleep(100_000);
            $this->container = $this->startContainer($container, $startId);
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

        if ($this->tempSigningKeyFile !== null && file_exists($this->tempSigningKeyFile)) {
            unlink($this->tempSigningKeyFile);
            $this->tempSigningKeyFile = null;
        }
    }

    public function getClient(): Client
    {
        $baseUrl = $this->getBaseUrl();
        return new Client($baseUrl, $this->apiToken);
    }

    private function startContainer(GenericContainer $genericContainer, string $startId): StartedGenericContainer
    {
        try {
            return $genericContainer->start();
        } catch (Exception $exception) {
            // Testcontainers leaves a container behind when anything fails
            // after it was created, and only the ContainerException for a
            // container that does not become ready carries its ID. If starting
            // it fails instead, the ID is not exposed, and the retry in start()
            // would swallow the error. So the containers of this start are
            // looked up by their label and removed before the exception moves
            // on. If removing them fails, PHP appends that error to the chain
            // of the original exception, which is still the one that is thrown.
            try {
                $this->removeContainers($startId);
            } finally {
                throw $exception;
            }
        }
    }

    private function removeContainers(string $startId): void
    {
        $dockerClient = DockerContainerClient::getDockerClient();
        $filters = json_encode([
            'label' => [self::START_LABEL . '=' . $startId],
        ], JSON_THROW_ON_ERROR);
        $deadline = microtime(true) + self::REMOVAL_TIMEOUT;

        while (true) {
            $containers = $dockerClient->containerList([
                'all' => true,
                'filters' => $filters,
            ]);
            if (!is_array($containers)) {
                throw new RuntimeException('Failed to list containers.');
            }

            if ($containers === []) {
                return;
            }

            $containerIds = array_map(
                static fn (ContainerSummary $containerSummary): string => (string) $containerSummary->getId(),
                $containers,
            );

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf(
                    'Failed to remove containers %s within %d seconds.',
                    implode(', ', $containerIds),
                    self::REMOVAL_TIMEOUT,
                ));
            }

            foreach ($containerIds as $containerId) {
                try {
                    $dockerClient->containerDelete($containerId, [
                        'force' => true,
                    ]);
                } catch (ContainerDeleteNotFoundException) {
                    // The container is already gone.
                } catch (ContainerDeleteConflictException) {
                    // The container is already being removed, so it is only
                    // waited for.
                }
            }

            usleep(100_000);
        }
    }

    private function runningContainer(): StartedGenericContainer
    {
        if (!$this->container instanceof StartedGenericContainer) {
            throw new RuntimeException('Container must be running');
        }

        return $this->container;
    }
}
