<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\Container;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;

class ClientTest extends TestCase
{
    public function testPingSucceedsWhenServerIsReachable(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
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
        $imageVersion = getImageVersionFromDockerfile();
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
        $imageVersion = getImageVersionFromDockerfile();
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
        $imageVersion = getImageVersionFromDockerfile();
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

    public function testWriteEventsWritesASingleEvent(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();
            $event = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 42,
                ],
            );

            $writtenEvents = $client->writeEvents([
                $event,
            ]);

            $this->assertCount(1, $writtenEvents);
            $this->assertSame('0', $writtenEvents[0]->id);
        } finally {
            $container->stop();
        }
    }

    public function testWriteEventsWritesMultipleEvents(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();

            $firstEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 23,
                ],
            );

            $secondEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 42,
                ],
            );

            $writtenEvents = $client->writeEvents([
                $firstEvent,
                $secondEvent,
            ]);

            $this->assertCount(2, $writtenEvents);
            $this->assertSame('0', $writtenEvents[0]->id);
            $this->assertSame(23, $writtenEvents[0]->data['value']);
            $this->assertSame('1', $writtenEvents[1]->id);
            $this->assertSame(42, $writtenEvents[1]->data['value']);
        } finally {
            $container->stop();
        }
    }

    public function testWriteEventsSupportsTheIsSubjectPristinePrecondition(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();

            $firstEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 23,
                ],
            );

            $client->writeEvents([
                $firstEvent,
            ]);

            $secondEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 42,
                ],
            );

            $this->expectExceptionMessage("Failed to write events, got HTTP status code '409', expected '200'");
            $client->writeEvents([
                $secondEvent,
            ], [
                new IsSubjectPristine('/test')
            ]);
        } finally {
            $container->stop();
        }
    }

    public function testWriteEventsSupportsTheIsSubjectOnEventIdPrecondition(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = new Container()->withImageTag($imageVersion);
        $container->start();
        try {
            $client = $container->getClient();

            $firstEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 23,
                ],
            );

            $client->writeEvents([
                $firstEvent,
            ]);

            $secondEvent = new EventCandidate(
                'https://www.eventsourcingdb.io',
                '/test',
                'io.eventsourcingdb.test',
                [
                    'value' => 42,
                ],
            );

            $this->expectExceptionMessage("Failed to write events, got HTTP status code '409', expected '200'");
            $client->writeEvents([
                $secondEvent,
            ], [
                new IsSubjectOnEventId('/test', '1')
            ]);
        } finally {
            $container->stop();
        }
    }
}
