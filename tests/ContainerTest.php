<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use Docker\API\Exception\ContainerCreateBadRequestException;
use Docker\API\Exception\ContainerDeleteConflictException;
use Docker\API\Exception\ContainerDeleteNotFoundException;
use Docker\API\Exception\ImageCreateNotFoundException;
use Docker\API\Exception\ImageInspectNotFoundException;
use Docker\API\Model\ContainerCreateResponse;
use Docker\API\Model\ContainersIdJsonGetResponse200;
use Docker\API\Model\ContainerState;
use Docker\API\Model\ContainerSummary;
use Docker\API\Model\ErrorResponse;
use Docker\API\Runtime\Client\Endpoint;
use Docker\Docker;
use Docker\Stream\DockerRawStream;
use Exception;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Testcontainers\ContainerClient\DockerContainerClient;
use Thenativeweb\Eventsourcingdb\Container;
use Thenativeweb\Eventsourcingdb\Tests\Docker\RecordingDocker;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageTag;

final class ContainerTest extends TestCase
{
    use ReflectionTestTrait;

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
        $recordingDocker = $this->useRecordingDocker();

        $imageTag = getImageTag();
        $this->container = (new Container())
            ->withImageTag($imageTag)
            ->withPort(4000);
        $this->container->start();

        $client = $this->container->getClient();

        $client->ping();
        $this->assertCount(1, $recordingDocker->startLabels);
    }

    public function testReadsTheMappedPortAgainOnEveryPoll(): void
    {
        // Docker shows the published port of each container only from its
        // fourth inspection on, as if it published the port late.
        $recordingDocker = $this->useRecordingDocker(inspectionsWithoutPorts: 3);

        $imageTag = getImageTag();
        $this->container = (new Container())->withImageTag($imageTag);
        $this->container->start();

        $client = $this->container->getClient();

        $client->ping();
        $this->assertCount(1, $recordingDocker->startLabels);
    }

    public function testReplacesContainersThatDoNotBecomeReachable(): void
    {
        // Docker never shows the published port, so the database runs, but
        // never becomes reachable.
        $recordingDocker = $this->useRecordingDocker(inspectionsWithoutPorts: null);

        $imageTag = getImageTag();
        $this->container = (new Container())
            ->withImageTag($imageTag)
            ->withStartupTimeout(0.5);

        $startedAt = microtime(true);
        $exception = $this->startAndCatch($this->container);
        $elapsed = microtime(true) - $startedAt;

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Failed to start the container, the database did not become reachable within 0.5s in any of 3 containers',
            $exception->getMessage(),
        );
        $this->assertFalse($this->container->isRunning());
        $this->assertCount(3, $recordingDocker->startLabels);
        $this->assertCount(3, array_unique($recordingDocker->startLabels));
        $this->assertNoContainersAreLeft($recordingDocker);

        // Each of the three containers gets the configured startup timeout,
        // rather than the default of 30 seconds.
        $this->assertGreaterThanOrEqual(1.5, $elapsed);
        $this->assertLessThan(30.0, $elapsed);
    }

    public function testFailsAtOnceIfTheContainerExits(): void
    {
        $recordingDocker = $this->useRecordingDocker();

        // Without an API token, EventSourcingDB exits right away.
        $imageTag = getImageTag();
        $this->container = (new Container())
            ->withImageTag($imageTag)
            ->withApiToken('');

        $exception = $this->startAndCatch($this->container);

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertFalse($this->container->isRunning());
        $this->assertCount(1, $recordingDocker->startLabels);

        // EventSourcingDB writes its error and its usage to different
        // streams, so where the error lands in the log varies, and the
        // message is compared to the end of the whole log instead.
        $this->assertCount(1, $recordingDocker->logsOfRemovedContainers);
        $logLines = explode("\n", rtrim(array_values($recordingDocker->logsOfRemovedContainers)[0]));
        $this->assertGreaterThan(20, count($logLines));
        $this->assertSame(
            'Failed to start the container, it exited before the database became reachable: ' .
            implode("\n", array_slice($logLines, -20)),
            $exception->getMessage(),
        );
        $this->assertNoContainersAreLeft($recordingDocker);
    }

    public function testFailsAtOnceIfDockerRejectsTheImage(): void
    {
        $recordingDocker = $this->useRecordingDocker();

        // Docker rejects the reference on its own, without asking a registry.
        $this->container = (new Container())->withImageTag('not a valid tag');

        $exception = $this->startAndCatch($this->container);

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $previous = $exception->getPrevious();
        $this->assertInstanceOf(ContainerCreateBadRequestException::class, $previous);
        $this->assertSame(
            'Failed to start the container: ' . $previous->getErrorResponse()->getMessage(),
            $exception->getMessage(),
        );
        $this->assertFalse($this->container->isRunning());
        $this->assertCount(1, $recordingDocker->startLabels);
        $this->assertNoContainersAreLeft($recordingDocker);
    }

    public function testFailsAtOnceIfPullingTheImageFails(): void
    {
        $docker = $this->useFakeDocker(function (string $containerId): void {
            unset($this->fakeContainers[$containerId]);
        });
        $docker->method('imageInspect')
            ->willThrowException(new ImageInspectNotFoundException(
                (new ErrorResponse())->setMessage('No such image: thenativeweb/eventsourcingdb:latest'),
                $this->createStub(ResponseInterface::class),
            ));
        $imageCreateNotFoundException = new ImageCreateNotFoundException(
            (new ErrorResponse())->setMessage('manifest for thenativeweb/eventsourcingdb:latest not found: manifest unknown'),
            $this->createStub(ResponseInterface::class),
        );
        $docker->method('executeEndpoint')
            ->willThrowException($imageCreateNotFoundException);

        $exception = $this->startAndCatch(new Container());

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Failed to start the container: manifest for thenativeweb/eventsourcingdb:latest not found: manifest unknown',
            $exception->getMessage(),
        );
        $this->assertSame($imageCreateNotFoundException, $exception->getPrevious());
        $this->assertSame(0, $this->fakeContainersCreated);
    }

    public function testEndsTheMessageOfAnExitedContainerWithTheLast20LinesOfItsLog(): void
    {
        // Docker's own tail counts the blank lines at the end of a log, so
        // asking it for 20 lines would yield only the last 18 of these.
        $logLines = [...array_map(static fn (int $number): string => "line {$number}", range(1, 25)), '', ''];

        $docker = $this->useFakeDocker(
            function (string $containerId): void {
                unset($this->fakeContainers[$containerId]);
            },
            static function (): void {},
        );
        $docker->method('containerInspect')
            ->willReturn(
                (new ContainersIdJsonGetResponse200())->setState((new ContainerState())->setStatus('exited')),
            );
        $docker->method('executeEndpoint')
            ->willReturnCallback(static function (Endpoint $endpoint) use ($logLines): DockerRawStream {
                // Answers like Docker, which returns the given number of log
                // entries from the end, blank ones included, as frames of
                // stdout and stderr.
                parse_str($endpoint->getQueryString(), $queryParameters);
                $tail = $queryParameters['tail'] ?? 'all';
                $entries = $tail === 'all' ? $logLines : array_slice($logLines, -(int) $tail);

                $frames = '';
                foreach ($entries as $index => $entry) {
                    $frames .= pack('CxxxN', $index % 2 === 0 ? 1 : 2, strlen($entry) + 1) . $entry . "\n";
                }

                return new DockerRawStream(Stream::create($frames));
            });

        $exception = $this->startAndCatch(new Container());

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Failed to start the container, it exited before the database became reachable: ' .
            implode("\n", array_slice($logLines, 5, 20)),
            $exception->getMessage(),
        );
        $this->assertSame(1, $this->fakeContainersCreated);
        $this->assertSame(['container-1'], $this->deletedContainerIds);
    }

    public function testUsesAStartupTimeoutOf30SecondsByDefault(): void
    {
        $this->assertEqualsWithDelta(30.0, $this->getPropertyValue(new Container(), 'startupTimeout'), PHP_FLOAT_EPSILON);
    }

    public function testUsesTheConfiguredStartupTimeout(): void
    {
        $container = (new Container())->withStartupTimeout(1.5);

        $this->assertEqualsWithDelta(1.5, $this->getPropertyValue($container, 'startupTimeout'), PHP_FLOAT_EPSILON);
    }

    #[DataProvider('provideInvalidStartupTimeouts')]
    public function testRejectsAStartupTimeoutThatIsNotAPositiveNumberOfSeconds(float $startupTimeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The startup timeout must be a positive number of seconds');

        (new Container())->withStartupTimeout($startupTimeout);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideInvalidStartupTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    public function testFailsAtOnceAndRemovesTheContainerIfDockerFailsToStartIt(): void
    {
        $this->useFakeDocker(function (string $containerId): void {
            unset($this->fakeContainers[$containerId]);
        });

        $exception = $this->startAndCatch(new Container());

        $this->assertDockerFailure($exception);
        $this->assertNull($exception?->getPrevious()?->getPrevious());
        $this->assertSame(['container-1'], $this->deletedContainerIds);
        $this->assertSame([], $this->fakeContainers);
    }

    public function testTreatsContainersThatAreAlreadyGoneAsRemoved(): void
    {
        $this->useFakeDocker(function (string $containerId): never {
            unset($this->fakeContainers[$containerId]);

            throw new ContainerDeleteNotFoundException(new ErrorResponse(), $this->createStub(ResponseInterface::class));
        });

        $exception = $this->startAndCatch(new Container());

        $this->assertDockerFailure($exception);
        $this->assertNull($exception?->getPrevious()?->getPrevious());
        $this->assertSame(['container-1'], $this->deletedContainerIds);
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

        $exception = $this->startAndCatch(new Container());

        $this->assertDockerFailure($exception);
        $this->assertNull($exception?->getPrevious()?->getPrevious());
        $this->assertSame([], $this->fakeContainers);
    }

    public function testThrowsTheOriginalExceptionIfTheContainersAreNotRemovedInTime(): void
    {
        // Docker accepts every removal, but keeps listing the containers.
        $this->useFakeDocker(static function (): void {});

        $exception = $this->startAndCatch(new Container());

        $this->assertDockerFailure($exception);
        $previous = $exception?->getPrevious()?->getPrevious();
        $this->assertInstanceOf(RuntimeException::class, $previous);
        $this->assertSame(
            'Failed to remove containers container-1 within 5 seconds.',
            $previous->getMessage(),
        );
    }

    /**
     * Asserts that starting failed at once with the error of the fake
     * Docker client, after it had created a single container.
     */
    private function assertDockerFailure(?Exception $exception): void
    {
        $this->assertCount(1, $this->startExceptions);
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Failed to start the container: Failed to start container-1.', $exception->getMessage());
        $this->assertSame($this->startExceptions[0], $exception->getPrevious());
        $this->assertSame(1, $this->fakeContainersCreated);
    }

    /**
     * Replaces the Docker client of Testcontainers with one that talks to the
     * real Docker, and records the containers it creates.
     */
    private function useRecordingDocker(?int $inspectionsWithoutPorts = 0): RecordingDocker
    {
        $this->realDockerClient = DockerContainerClient::getDockerClient();

        $recordingDocker = RecordingDocker::connect();
        $recordingDocker->inspectionsWithoutPorts = $inspectionsWithoutPorts;
        DockerContainerClient::setDockerClient($recordingDocker);

        return $recordingDocker;
    }

    /**
     * Asserts that no container is left that carries the start label of any
     * container the recording Docker client created.
     */
    private function assertNoContainersAreLeft(RecordingDocker $recordingDocker): void
    {
        foreach ($recordingDocker->startLabels as $startLabel) {
            $containers = $recordingDocker->containerList([
                'all' => true,
                'filters' => json_encode([
                    'label' => ['io.eventsourcingdb.container-start=' . $startLabel],
                ], JSON_THROW_ON_ERROR),
            ]);

            $this->assertSame([], $containers);
        }
    }

    /**
     * Replaces the Docker client of Testcontainers with a fake one, which
     * creates containers that then fail to start, unless the given function
     * starts them instead. Removing a container is left to the given function.
     *
     * @param Closure(string): void $deleteContainer
     * @param (Closure(string): void)|null $startContainer
     */
    private function useFakeDocker(Closure $deleteContainer, ?Closure $startContainer = null): Stub&Docker
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
            ->willReturnCallback($startContainer ?? function (string $containerId): never {
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

        return $docker;
    }

    private function startAndCatch(Container $container): ?Exception
    {
        $exception = null;
        try {
            $container->start();
        } catch (Exception $caughtException) {
            $exception = $caughtException;
        }

        return $exception;
    }
}
