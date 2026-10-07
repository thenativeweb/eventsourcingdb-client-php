<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Docker\API\Exception\ContainerDeleteConflictException;
use Docker\API\Exception\ContainerDeleteNotFoundException;
use Docker\API\Model\ContainerCreateResponse;
use Docker\API\Model\ContainerSummary;
use Docker\API\Model\ErrorResponse;
use Docker\Docker;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Testcontainers\ContainerClient\DockerContainerClient;
use Testcontainers\Exception\ContainerException;
use Thenativeweb\Eventsourcingdb\Container;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageVersionFromDockerfile;

final class ContainerTest extends TestCase
{
    private ?Container $container = null;

    private ?Docker $realDockerClient = null;

    /**
     * The containers the fake Docker client knows, each with the number of
     * further listings it still shows up in, or null if it stays until it is
     * removed.
     *
     * @var array<string, ?int>
     */
    private array $fakeContainers = [];

    private int $fakeContainersCreated = 0;

    /**
     * @var list<RuntimeException>
     */
    private array $startExceptions = [];

    /**
     * @var list<string>
     */
    private array $deletedContainerIds = [];

    protected function tearDown(): void
    {
        $this->container?->stop();

        if ($this->realDockerClient instanceof Docker) {
            DockerContainerClient::setDockerClient($this->realDockerClient);
        }

        parent::tearDown();
    }

    public function testStartsWithACustomPort(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $this->container = (new Container())
            ->withImageTag($imageVersion)
            ->withPort(4000);
        $this->container->start();

        $client = $this->container->getClient();

        $client->ping();
        $this->expectNotToPerformAssertions();
    }

    public function testRemovesContainersThatDoNotBecomeReady(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $containerIdsBefore = $this->getStartedContainerIds();

        // Without an API token, EventSourcingDB exits right away.
        $this->container = (new Container())
            ->withImageTag($imageVersion)
            ->withApiToken('');

        $exception = null;
        try {
            $this->container->start();
        } catch (ContainerException $containerException) {
            $exception = $containerException;
        }

        $this->assertInstanceOf(ContainerException::class, $exception);
        $this->assertSame([], array_values(array_diff($this->getStartedContainerIds(), $containerIdsBefore)));
    }

    public function testRemovesContainersThatFailAfterTheyWereCreated(): void
    {
        $this->useFakeDocker(function (string $containerId): void {
            unset($this->fakeContainers[$containerId]);
        });

        $exception = $this->startAndCatch();

        $this->assertSame($this->startExceptions[1], $exception);
        $this->assertNull($exception->getPrevious());
        $this->assertSame(['container-1', 'container-2'], $this->deletedContainerIds);
        $this->assertSame([], $this->fakeContainers);
    }

    public function testTreatsContainersThatAreAlreadyGoneAsRemoved(): void
    {
        $this->useFakeDocker(function (string $containerId): never {
            unset($this->fakeContainers[$containerId]);

            throw new ContainerDeleteNotFoundException(new ErrorResponse(), $this->createStub(ResponseInterface::class));
        });

        $exception = $this->startAndCatch();

        $this->assertSame($this->startExceptions[1], $exception);
        $this->assertNull($exception->getPrevious());
        $this->assertSame(['container-1', 'container-2'], $this->deletedContainerIds);
        $this->assertSame([], $this->fakeContainers);
    }

    public function testWaitsForARemovalThatIsAlreadyInProgress(): void
    {
        $this->useFakeDocker(function (string $containerId): never {
            // Someone else is already removing the container, and it shows
            // up in two more listings before it is gone.
            $this->fakeContainers[$containerId] ??= 2;

            throw new ContainerDeleteConflictException(new ErrorResponse(), $this->createStub(ResponseInterface::class));
        });

        $exception = $this->startAndCatch();

        $this->assertSame($this->startExceptions[1], $exception);
        $this->assertNull($exception->getPrevious());
        $this->assertSame([], $this->fakeContainers);
    }

    public function testThrowsTheOriginalExceptionIfTheContainersAreNotRemovedInTime(): void
    {
        // Docker accepts every removal, but keeps listing the containers.
        $this->useFakeDocker(static function (): void {});

        $exception = $this->startAndCatch();

        $this->assertSame($this->startExceptions[1], $exception);
        $previous = $exception->getPrevious();
        $this->assertInstanceOf(RuntimeException::class, $previous);
        $this->assertSame(
            'Failed to remove containers container-1, container-2 within 5 seconds.',
            $previous->getMessage(),
        );
    }

    /**
     * Replaces the Docker client of Testcontainers with a fake one, which
     * creates containers that then fail to start. Removing a container is
     * left to the given function.
     *
     * @param Closure(string): void $deleteContainer
     */
    private function useFakeDocker(Closure $deleteContainer): void
    {
        $this->realDockerClient = DockerContainerClient::getDockerClient();

        $docker = $this->createStub(Docker::class);
        $docker->method('containerCreate')
            ->willReturnCallback(function (): ContainerCreateResponse {
                $containerId = 'container-' . ++$this->fakeContainersCreated;
                $this->fakeContainers[$containerId] = null;

                return (new ContainerCreateResponse())->setId($containerId);
            });
        $docker->method('containerStart')
            ->willReturnCallback(function (string $containerId): never {
                $runtimeException = new RuntimeException("Failed to start {$containerId}.");
                $this->startExceptions[] = $runtimeException;

                throw $runtimeException;
            });
        $docker->method('containerList')
            ->willReturnCallback(function (): array {
                $containerSummaries = [];
                foreach ($this->fakeContainers as $containerId => $listingsLeft) {
                    if ($listingsLeft === 0) {
                        unset($this->fakeContainers[$containerId]);
                        continue;
                    }

                    if ($listingsLeft !== null) {
                        $this->fakeContainers[$containerId] = $listingsLeft - 1;
                    }

                    $containerSummaries[] = (new ContainerSummary())->setId($containerId);
                }

                return $containerSummaries;
            });
        $docker->method('containerDelete')
            ->willReturnCallback(
                function (string $containerId, array $queryParameters) use ($deleteContainer): void {
                    $this->assertSame([
                        'force' => true,
                    ], $queryParameters);
                    $this->deletedContainerIds[] = $containerId;
                    $deleteContainer($containerId);
                },
            );

        DockerContainerClient::setDockerClient($docker);
    }

    private function startAndCatch(): ?Exception
    {
        $exception = null;
        try {
            (new Container())->start();
        } catch (Exception $caughtException) {
            $exception = $caughtException;
        }

        return $exception;
    }

    /**
     * @return list<string>
     */
    // Lists the containers that a Container of this SDK started, by the label
    // it puts on them. Containers that anything else starts at the same time,
    // e.g. the test suite of another SDK, do not carry it.
    private function getStartedContainerIds(): array
    {
        $containers = DockerContainerClient::getDockerClient()->containerList([
            'all' => true,
            'filters' => json_encode([
                'label' => ['io.eventsourcingdb.container-start'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertIsArray($containers);

        $containerIds = array_map(
            static fn (ContainerSummary $containerSummary): string => (string) $containerSummary->getId(),
            $containers,
        );
        sort($containerIds);

        return $containerIds;
    }
}
