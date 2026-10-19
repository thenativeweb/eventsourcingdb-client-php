<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Docker;

use Docker\API\Model\ContainersCreatePostBody;
use Docker\API\Model\ContainersIdJsonGetResponse200;
use Docker\Docker;
use Docker\DockerClientFactory;
use Docker\Endpoint\ContainerLogs;
use Docker\Stream\DockerRawStream;
use Exception;
use RuntimeException;

/**
 * Talks to the real Docker, and records the start labels of the containers it
 * creates, so that a test knows how many containers a start created, and can
 * check that none of them is left, without looking at containers that anything
 * else starts at the same time. It also records the whole log of each
 * container right before removing it. It can also hide the published ports of
 * a container from its first inspections, or from all of them, as if Docker
 * published them late, or not at all.
 */
final class RecordingDocker extends Docker
{
    /**
     * @var list<string>
     */
    public array $startLabels = [];

    /**
     * The whole log of each removed container, by its ID.
     *
     * @var array<string, string>
     */
    public array $logsOfRemovedContainers = [];

    /**
     * The number of inspections of each container that do not show its
     * published ports, or null for all of them.
     */
    public ?int $inspectionsWithoutPorts = 0;

    /**
     * @var array<string, int>
     */
    private array $inspections = [];

    public static function connect(): self
    {
        $docker = self::create(DockerClientFactory::createFromEnv());
        if (!$docker instanceof self) {
            throw new RuntimeException('Failed to create a recording Docker client.');
        }

        return $docker;
    }

    public function containerCreate(?ContainersCreatePostBody $requestBody = null, array $queryParameters = [], string $fetch = self::FETCH_OBJECT)
    {
        $this->startLabels[] = $requestBody?->getLabels()['io.eventsourcingdb.container-start'] ?? '';

        return parent::containerCreate($requestBody, $queryParameters, $fetch);
    }

    public function containerInspect(string $id, array $queryParameters = [], string $fetch = self::FETCH_OBJECT)
    {
        $containerInspect = parent::containerInspect($id, $queryParameters, $fetch);
        $this->inspections[$id] = ($this->inspections[$id] ?? 0) + 1;

        if (
            $containerInspect instanceof ContainersIdJsonGetResponse200 &&
            ($this->inspectionsWithoutPorts === null || $this->inspections[$id] <= $this->inspectionsWithoutPorts)
        ) {
            $containerInspect->getNetworkSettings()?->setPorts([]);
        }

        return $containerInspect;
    }

    public function containerDelete(string $id, array $queryParameters = [], string $fetch = self::FETCH_OBJECT, array $accept = [])
    {
        if (!isset($this->logsOfRemovedContainers[$id])) {
            try {
                $logs = '';
                $dockerRawStream = $this->executeEndpoint(new ContainerLogs($id, [
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

                $this->logsOfRemovedContainers[$id] = $logs;
            } catch (Exception) {
                // The container is already gone, so there is no log to record.
            }
        }

        return parent::containerDelete($id, $queryParameters, $fetch, $accept);
    }
}
