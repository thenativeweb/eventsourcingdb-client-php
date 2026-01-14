<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\Container;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\SigningKey;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageVersionFromDockerfile;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;

final class CloudEventSignatureTest extends TestCase
{
    use ReflectionTestTrait;

    private Container $container;

    protected function tearDown(): void
    {
        $this->container->stop();
        parent::tearDown();
    }

    public function testThrowsAnErrorIfTheSignatureIsNull(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())
            ->withImageTag($imageVersion);
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        $this->assertNull($writtenEvent->signature);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signature must not be null.');

        $signingKey = new SigningKey();

        $writtenEvent->verifySignature($signingKey->ed25519->publicKey);
    }

    public function testThrowsAnErrorIfTheHashVerificationFails(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())
            ->withImageTag($imageVersion)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        $this->assertNotNull($writtenEvent->signature);

        $tamperedCloudEvent = new CloudEvent(
            specVersion: $writtenEvent->specVersion,
            id: $writtenEvent->id,
            time: $writtenEvent->time,
            timeFromServer: $this->getPropertyValue($writtenEvent, 'timeFromServer'),
            source: $writtenEvent->source,
            subject: $writtenEvent->subject,
            type: $writtenEvent->type,
            dataContentType: $writtenEvent->dataContentType,
            data: $writtenEvent->data,
            hash: hash('sha256', 'invalid hash'),
            predecessorHash: $writtenEvent->predecessorHash,
            traceParent: $writtenEvent->traceParent,
            traceState: $writtenEvent->traceState,
            signature: $writtenEvent->signature,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to verify hash.');

        $verificationKey = $container->getVerificationKey();

        $tamperedCloudEvent->verifySignature($verificationKey);
    }

    public function testThrowsAnErrorIfTheSignatureVerificationFails(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())
            ->withImageTag($imageVersion)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        $this->assertNotNull($writtenEvent->signature);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signature verification failed.');

        $tamperedCloudEvent = new CloudEvent(
            specVersion: $writtenEvent->specVersion,
            id: $writtenEvent->id,
            time: $writtenEvent->time,
            timeFromServer: $this->getPropertyValue($writtenEvent, 'timeFromServer'),
            source: $writtenEvent->source,
            subject: $writtenEvent->subject,
            type: $writtenEvent->type,
            dataContentType: $writtenEvent->dataContentType,
            data: $writtenEvent->data,
            hash: $writtenEvent->hash,
            predecessorHash: $writtenEvent->predecessorHash,
            traceParent: $writtenEvent->traceParent,
            traceState: $writtenEvent->traceState,
            signature: 'esdb:signature:v1:14b9f0275b53800b222e0c80bce8e544be99247d0621cc865e7f9e751fbbe7f6bb724a2be563a39606a3f7dc743f97189a9ccd44ce66bd016e6370250cd99d09',
        );

        $signingKey = new SigningKey();

        $tamperedCloudEvent->verifySignature($signingKey->ed25519->publicKey);
    }

    public function testVerifiesTheSignature(): void
    {
        $imageVersion = getImageVersionFromDockerfile();
        $container = (new Container())
            ->withImageTag($imageVersion)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $eventCandidate = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $writtenEvents = $client->writeEvents([
            $eventCandidate,
        ]);

        $this->assertCount(1, $writtenEvents);

        $writtenEvent = $writtenEvents[0];

        $this->assertNotNull($writtenEvent->signature);

        $verificationKey = $container->getVerificationKey();

        try {
            $writtenEvent->verifySignature($verificationKey);
        } catch (Exception $exception) {
            $this->fail($exception->getMessage());
        }
    }
}
