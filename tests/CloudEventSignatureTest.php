<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\Container;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\HashVerificationFailedException;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\SignatureVerificationFailedException;
use Thenativeweb\Eventsourcingdb\SigningKey;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ReflectionTestTrait;
use function Thenativeweb\Eventsourcingdb\Tests\Fn\getImageTag;

final class CloudEventSignatureTest extends TestCase
{
    use ReflectionTestTrait;

    private Container $container;

    protected function tearDown(): void
    {
        $this->container->stop();
        parent::tearDown();
    }

    public function testThrowsAnErrorIfVerificationKeyIsEmpty(): void
    {
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $writtenEvents = $client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
        ]);

        $this->assertCount(1, $writtenEvents);
        $this->assertNotNull($writtenEvents[0]->signature);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verification key must not be empty.');

        $writtenEvents[0]->verifySignature('');
    }

    public function testThrowsAnErrorIfSignatureHasInvalidLength(): void
    {
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $writtenEvents = $client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
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
            hash: $writtenEvent->hash,
            predecessorHash: $writtenEvent->predecessorHash,
            traceParent: $writtenEvent->traceParent,
            traceState: $writtenEvent->traceState,
            signature: 'esdb:signature:v1:deadbeef',
        );

        $this->expectException(SignatureVerificationFailedException::class);
        $this->expectExceptionMessage('Signature has an invalid length: expected 64 bytes, got 4 bytes.');

        $tamperedCloudEvent->verifySignature($container->getVerificationKey());
    }

    public function testThrowsAnErrorIfTheSignatureIsNull(): void
    {
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag);
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
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
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

        $this->expectException(HashVerificationFailedException::class);
        $this->expectExceptionMessage('Failed to verify hash.');

        $verificationKey = $container->getVerificationKey();

        $tamperedCloudEvent->verifySignature($verificationKey);
    }

    public function testThrowsAnErrorIfTheSignatureVerificationFails(): void
    {
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
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

        $this->expectException(SignatureVerificationFailedException::class);
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
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
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

    public function testVerifiesTheSignatureOfEventsAsTheyAreWrittenAndRead(): void
    {
        $imageTag = getImageTag();
        $container = (new Container())
            ->withImageTag($imageTag)
            ->withSigningKey();
        $container->start();
        $this->container = $container;

        $client = $container->getClient();

        $writtenEvents = $client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/books/42',
                type: 'io.eventsourcingdb.library.book-acquired',
                data: [
                    'title' => '2001 – A Space Odyssey',
                    'shelf' => 'A&B <classic>',
                    'price' => 12.5,
                ],
                traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
                traceState: 'rojo=00f067aa0ba902b7',
            ),
        ]);
        $readEvents = iterator_to_array($client->readEvents('/books/42', new ReadEventsOptions(recursive: false)), false);

        $this->assertCount(1, $writtenEvents);
        $this->assertCount(1, $readEvents);

        $verificationKey = $container->getVerificationKey();

        foreach ([...$writtenEvents, ...$readEvents] as $event) {
            $this->assertInstanceOf(CloudEvent::class, $event);
            $this->assertNotNull($event->signature);

            try {
                $event->verifySignature($verificationKey);
            } catch (Exception $exception) {
                $this->fail("Failed to verify the signature of event {$event->id} with hash '{$event->hash}': {$exception->getMessage()}");
            }
        }
    }
}
