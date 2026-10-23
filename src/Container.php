<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use Docker\API\Exception\ContainerDeleteConflictException;
use Docker\API\Exception\ContainerDeleteNotFoundException;
use Docker\API\Exception\ImageInspectNotFoundException;
use Docker\API\Model\ContainersIdJsonGetResponse200;
use Docker\API\Model\ContainerSummary;
use Docker\API\Model\CreateImageInfo;
use Docker\API\Model\ErrorResponse;
use Docker\API\Model\PortBinding;
use Docker\Docker;
use Docker\Endpoint\ContainerLogs;
use Docker\Endpoint\ImageCreate;
use Docker\Stream\CreateImageStream;
use Docker\Stream\DockerRawStream;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Container\StartedTestContainer;
use Testcontainers\ContainerClient\DockerContainerClient;
use Testcontainers\Utils\DockerAuthConfig;
use Testcontainers\Utils\HostResolver;
use Testcontainers\Utils\PortGenerator\PortGenerator;
use Testcontainers\Wait\WaitStrategy;
use Thenativeweb\Eventsourcingdb\Api\TransportSelector;
use Throwable;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\ContainerTest
 */
final class Container
{
    private const START_LABEL = 'io.eventsourcingdb.container-start';
    private const START_ATTEMPTS = 3;
    private const DEFAULT_STARTUP_TIMEOUT = 30.0;
    private const POLL_INTERVAL = 0.1;
    private const PING_TIMEOUT = 1.0;
    private const LOG_TAIL_LINES = 20;
    private const REMOVAL_TIMEOUT = 5;

    private string $imageName = 'thenativeweb/eventsourcingdb';
    private string $imageTag = 'latest';
    private int $internalPort = 3000;
    private string $apiToken = 'secret';
    private float $startupTimeout = self::DEFAULT_STARTUP_TIMEOUT;
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
     * @param float $seconds the seconds that each container gets to become reachable, from creating it until the database in it answers a ping, without pulling the image, which defaults to 30
     */
    public function withStartupTimeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException(sprintf(
                'The startup timeout must be a positive number of seconds, got %s.',
                var_export($seconds, true),
            ));
        }

        $this->startupTimeout = $seconds;
        return $this;
    }

    /**
     * Starts a container and waits until the database in it answers a ping,
     * for the startup timeout at most. A container that keeps running without
     * becoming reachable is replaced, up to three containers in total. If
     * Docker fails, or if the container exits, starting fails at once. Every
     * container that does not start is removed.
     *
     * @throws RuntimeException
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

            $this->tempSigningKeyFile = getcwd() . '/.esdb_signing_key_' . uniqid();
            file_put_contents($this->tempSigningKeyFile, $this->signingKey->privateKeyPem);
            chmod($this->tempSigningKeyFile, 0o644);
        }

        $dockerClient = $this->callDocker(static fn (): Docker => DockerContainerClient::getDockerClient());
        $this->callDocker(fn () => $this->pullImageIfMissing($dockerClient));
        $host = $this->callDocker(static fn (): string => (new HostResolver($dockerClient))->resolveHost());

        for ($attempt = 1; $attempt <= self::START_ATTEMPTS; ++$attempt) {
            $startedContainer = $this->startAttempt($dockerClient, $command, $host);
            if ($startedContainer instanceof StartedGenericContainer) {
                $this->container = $startedContainer;
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'Failed to start the container, the database did not become reachable within %ss in any of %d containers',
            $this->startupTimeout,
            self::START_ATTEMPTS,
        ));
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

    /**
     * Starts one container, with a label of its own, and returns it once the
     * database in it is reachable. If it keeps running without becoming
     * reachable within the startup timeout, it is removed, and null is
     * returned.
     *
     * @param list<string> $command
     */
    private function startAttempt(Docker $docker, array $command, string $host): ?StartedGenericContainer
    {
        $startId = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $this->startupTimeout;

        try {
            $startedContainer = $this->callDocker(fn (): StartedGenericContainer => $this->createContainer($command, $startId)->start());

            if ($this->waitUntilReachable($docker, $startedContainer->getId(), $host, $deadline)) {
                return $startedContainer;
            }
        } catch (Throwable $throwable) {
            // A container stays behind when anything fails after it was
            // created, and Testcontainers does not expose its ID if creating
            // or starting it fails. So the containers of this attempt are
            // looked up by their label and removed before the exception moves
            // on, whatever failed. If removing them fails,
            // PHP appends that error to the chain of the original exception,
            // which is still the one that is thrown.
            try {
                $this->removeContainers($startId);
            } finally {
                throw $throwable;
            }
        }

        $this->removeContainers($startId);

        return null;
    }

    /**
     * @param list<string> $command
     */
    private function createContainer(array $command, string $startId): GenericContainer
    {
        $genericContainer = (new GenericContainer("{$this->imageName}:{$this->imageTag}"))
            ->withExposedPorts($this->internalPort)
            // With port 0, Docker picks a free host port itself, so that no
            // other process can take the port between choosing and binding it.
            ->withPortGenerator(new class() implements PortGenerator {
                public function generatePort(): int
                {
                    return 0;
                }
            })
            ->withCommand($command)
            ->withLabels([
                self::START_LABEL => $startId,
            ])
            // The container is waited for by waitUntilReachable instead,
            // which looks at its state and its port anew on every poll.
            ->withWait(new class() implements WaitStrategy {
                public function wait(StartedTestContainer $container): void
                {
                }
            });

        if ($this->tempSigningKeyFile !== null) {
            return $genericContainer->withMount($this->tempSigningKeyFile, '/etc/esdb/signing-key.pem');
        }

        return $genericContainer;
    }

    /**
     * Polls the container until the database in it answers a ping with 200,
     * see answersPing, and tells whether it did so before the deadline. The
     * mapped port is read anew on every poll, since Docker sometimes
     * publishes it late. If the container exits, waiting fails at once with
     * the end of its log.
     */
    private function waitUntilReachable(Docker $docker, string $containerId, string $host, float $deadline): bool
    {
        while (true) {
            $containerInspect = $this->callDocker(static fn (): mixed => $docker->containerInspect($containerId));
            if (!$containerInspect instanceof ContainersIdJsonGetResponse200) {
                $containerInspect = null;
            }

            $status = $containerInspect?->getState()?->getStatus();
            if ($status === 'exited' || $status === 'dead') {
                throw new RuntimeException(
                    'Failed to start the container, it exited before the database became reachable: ' .
                    $this->readLogTail($docker, $containerId),
                );
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }

            $port = $this->findMappedPort($containerInspect?->getNetworkSettings()?->getPorts());
            if ($port !== null && $this->answersPing($host, $port, $deadline)) {
                return true;
            }

            $remaining = $deadline - microtime(true);
            usleep((int) (max(min($remaining, self::POLL_INTERVAL), 0.0) * 1_000_000));
        }
    }

    /**
     * @param iterable<string, ?list<PortBinding>>|null $ports
     */
    private function findMappedPort(?iterable $ports): ?int
    {
        if ($ports === null) {
            return null;
        }

        $portBinding = iterator_to_array($ports)["{$this->internalPort}/tcp"][0] ?? null;
        $hostPort = $portBinding instanceof PortBinding ? (int) $portBinding->getHostPort() : 0;

        return $hostPort > 0 ? $hostPort : null;
    }

    /**
     * Tells whether the database answers its ping with 200. It asks the ping
     * of the v2 API, and only if the server answers that in a way that means
     * that it does not offer the v2 API, with 404 or 405, the ping of the v1
     * API, which later releases no longer offer. Any other outcome, such as a
     * refused connection, a request that takes too long, or another status,
     * means that the database does not answer yet.
     *
     * The container shares only what such an answer means with the client,
     * not the client's choice of the API, so that a defect in that choice
     * fails the tests that pin it instead of the start of every container.
     */
    private function answersPing(string $host, int $port, float $deadline): bool
    {
        $status = $this->requestPing("http://{$host}:{$port}/api/v2/system/ping", $deadline);
        if (TransportSelector::meansNoV2Api($status)) {
            $status = $this->requestPing("http://{$host}:{$port}/api/v1/ping", $deadline);
        }

        return $status === 200;
    }

    /**
     * Asks the given URL without a token, for the ping timeout at most, and
     * for no longer than is left until the deadline, and returns the status
     * of the answer, or null if none arrived.
     */
    private function requestPing(string $url, float $deadline): ?int
    {
        $curlHandle = curl_init($url);
        if ($curlHandle === false) {
            return null;
        }

        // cURL takes a timeout of 0 for no timeout at all, so a request gets a
        // millisecond at least, also once the deadline has passed.
        $timeout = min($deadline - microtime(true), self::PING_TIMEOUT);
        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
        ]);

        if (curl_exec($curlHandle) === false) {
            return null;
        }

        return curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
    }

    private function readLogTail(Docker $docker, string $containerId): string
    {
        $logs = $this->callDocker(static function () use ($docker, $containerId): string {
            $logs = '';
            // The endpoint is executed directly, since the declared return
            // type of containerLogs does not cover the stream it returns. The
            // whole log is read rather than Docker's tail of it, which counts
            // blank lines at the end, so that the tail below holds as many
            // lines as it can.
            $dockerRawStream = $docker->executeEndpoint(new ContainerLogs($containerId, [
                'stdout' => true,
                'stderr' => true,
            ]));

            if ($dockerRawStream instanceof DockerRawStream) {
                $appendOutput = static function (string $output) use (&$logs): void {
                    $logs .= $output;
                };
                $dockerRawStream->onStdout($appendOutput);
                $dockerRawStream->onStderr($appendOutput);
                $dockerRawStream->wait();
            }

            return $logs;
        });

        $lines = preg_split('/\r?\n/', rtrim($logs)) ?: [];

        return implode("\n", array_slice($lines, -self::LOG_TAIL_LINES));
    }

    /**
     * Pulls the image, unless it is there already, so that pulling it does
     * not count towards the startup timeout of a container.
     */
    private function pullImageIfMissing(Docker $docker): void
    {
        try {
            $docker->imageInspect("{$this->imageName}:{$this->imageTag}");
            return;
        } catch (ImageInspectNotFoundException) {
            // The image is missing, so it is pulled.
        }

        $headers = [];
        $credentials = DockerAuthConfig::getInstance()->getAuthForRegistry(DockerAuthConfig::getRegistryFromImage($this->imageName));
        if ($credentials !== null) {
            $headers['X-Registry-Auth'] = base64_encode(json_encode($credentials, JSON_THROW_ON_ERROR));
        }

        // The endpoint is executed directly, since the declared return type
        // of imageCreate does not cover the stream it returns.
        $createImageStream = $docker->executeEndpoint(new ImageCreate(null, [
            'fromImage' => $this->imageName,
            'tag' => $this->imageTag,
        ], $headers));
        if (!$createImageStream instanceof CreateImageStream) {
            return;
        }

        // Docker reports some failures of a pull only in its stream.
        $error = null;
        $createImageStream->onFrame(static function (CreateImageInfo $createImageInfo) use (&$error): void {
            $error ??= $createImageInfo->getErrorDetail()?->getMessage() ?? $createImageInfo->getError();
        });
        $createImageStream->wait();

        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    /**
     * Calls Docker, and turns any exception into the one that starting a
     * container throws when Docker fails, with Docker's message, and with
     * Docker's exception as its previous one.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function callDocker(callable $call): mixed
    {
        try {
            return $call();
        } catch (Exception $exception) {
            $message = $exception->getMessage();
            if (method_exists($exception, 'getErrorResponse')) {
                $errorResponse = $exception->getErrorResponse();
                if ($errorResponse instanceof ErrorResponse && (string) $errorResponse->getMessage() !== '') {
                    $message = (string) $errorResponse->getMessage();
                }
            }

            throw new RuntimeException("Failed to start the container: {$message}", 0, $exception);
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
