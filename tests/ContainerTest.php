<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Docker\API\Model\ContainerSummary;
use PHPUnit\Framework\TestCase;
use Testcontainers\ContainerClient\DockerContainerClient;
use Testcontainers\Exception\ContainerException;
use Thenativeweb\Eventsourcingdb\Container;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageVersionFromDockerfile;

final class ContainerTest extends TestCase
{
    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container?->stop();
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
        $containerIdsBefore = $this->getContainerIds($imageVersion);

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
        $this->assertSame($containerIdsBefore, $this->getContainerIds($imageVersion));
    }

    /**
     * @return list<string>
     */
    private function getContainerIds(string $imageVersion): array
    {
        $containers = DockerContainerClient::getDockerClient()->containerList([
            'all' => true,
            'filters' => json_encode([
                'ancestor' => ["thenativeweb/eventsourcingdb:{$imageVersion}"],
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
