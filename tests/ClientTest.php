<?php

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Container;

class ClientTest extends TestCase
{
    public function testPingSucceedsWhenServerIsReachable(): void
    {
        $imageVersion = $this->getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();
            $client->ping();
            $this->expectNotToPerformAssertions();
        } finally {
            $container->stop();
        }
    }

    public function testPingFailsWhenServerIsUnreachable(): void
    {
        $imageVersion = $this->getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $port = $container->getMappedPort();
            $client = new Client("http://non-existent-host:{$port}", $container->getApiToken());

            $this->expectException(\Throwable::class);
            $client->ping();
        } finally {
            $container->stop();
        }
    }

    public function testVerifyApiTokenDoesNotThrowAnErrorIfTheTokenIsValid(): void
    {
        $imageVersion = $this->getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();
            $client->verifyApiToken();
            $this->expectNotToPerformAssertions();
        } finally {
            $container->stop();
        }
    }

    public function testVerifyApiTokenThrowsAnErrorIfTheTokenIsInvalid(): void
    {
        $imageVersion = $this->getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $baseUrl = $container->getBaseUrl();
            $apiToken = $container->getApiToken() . '-invalid';
            $client = new Client($baseUrl, $apiToken);
            $this->expectException(\Throwable::class);
            $client->verifyApiToken();
        } finally {
            $container->stop();
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
