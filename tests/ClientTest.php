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
    private Container $container;
    private Client $client;


    protected function setUp(): void
    {
        parent::setUp();
        $imageVersion = getImageVersionFromDockerfile();
        $this->container = (new Container())->withImageTag($imageVersion);
        $this->container->start();
        $this->client = $this->container->getClient();
    }


    public function testPingSucceedsWhenServerIsReachable(): void
    {
        $this->client->ping();
        $this->expectNotToPerformAssertions();
    }

    public function testPingFailsWhenServerIsUnreachable(): void
    {
        $port = $this->container->getMappedPort();
        $client = new Client("http://non-existent-host:{$port}", $this->container->getApiToken());

        $this->expectException(\Throwable::class);
        $client->ping();
    }

    protected function tearDown(): void
    {
        $this->container->stop();
        parent::tearDown();
    }


    public function testVerifyApiTokenDoesNotThrowAnErrorIfTheTokenIsValid(): void
    {
        $client = $this->container->getClient();
        $client->verifyApiToken();
        $this->expectNotToPerformAssertions();
    }

    public function testVerifyApiTokenThrowsAnErrorIfTheTokenIsInvalid(): void
    {
        $baseUrl = $this->container->getBaseUrl();
        $apiToken = $this->container->getApiToken() . '-invalid';
        $client = new Client($baseUrl, $apiToken);
        $this->expectException(\Throwable::class);
        $client->verifyApiToken();
    }

    public function testWriteEventsWritesASingleEvent(): void
    {
        $event = new EventCandidate(
            'https://www.eventsourcingdb.io',
            '/test',
            'io.eventsourcingdb.test',
            [
                'value' => 42,
            ],
        );

        $writtenEvents = $this->client->writeEvents([
            $event,
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertSame('0', $writtenEvents[0]->id);
    }

    public function testWriteEventsWritesMultipleEvents(): void
    {
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

        $writtenEvents = $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $this->assertCount(2, $writtenEvents);
        $this->assertSame('0', $writtenEvents[0]->id);
        $this->assertSame(23, $writtenEvents[0]->data['value']);
        $this->assertSame('1', $writtenEvents[1]->id);
        $this->assertSame(42, $writtenEvents[1]->data['value']);
    }

    public function testWriteEventsSupportsTheIsSubjectPristinePrecondition(): void
    {
        $firstEvent = new EventCandidate(
            'https://www.eventsourcingdb.io',
            '/test',
            'io.eventsourcingdb.test',
            [
                'value' => 23,
            ],
        );

        $this->client->writeEvents([
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

        $this->client->writeEvents([
            $secondEvent,
        ], [
            new IsSubjectPristine('/test')
        ]);
    }

    public function testWriteEventsSupportsTheIsSubjectOnEventIdPrecondition(): void
    {
        $firstEvent = new EventCandidate(
            'https://www.eventsourcingdb.io',
            '/test',
            'io.eventsourcingdb.test',
            [
                'value' => 23,
            ],
        );

        $this->client->writeEvents([
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
        $this->client->writeEvents([
            $secondEvent,
        ], [
            new IsSubjectOnEventId('/test', '1')
        ]);
    }
}
