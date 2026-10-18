<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Closure;
use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\HashVerificationFailedException;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\SignatureVerificationFailedException;
use Thenativeweb\Eventsourcingdb\Stream\RawJson;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;
use Thenativeweb\Eventsourcingdb\UnsupportedHashVersionException;

final class CloudEventHashTest extends TestCase
{
    use ServerTestTrait;

    // An event as EventSourcingDB sends it, byte for byte, with a hash of
    // version 2, taken from the test fixtures of EventSourcingDB. Its data
    // holds characters and numbers that PHP writes differently than the
    // server does, so its hash only matches if the data is hashed as received.
    private const EVENT_WITH_HASH_OF_VERSION_2 = '{"source":"tag:thenativeweb.io,2023:library","subject":"/books/42","type":"io.eventsourcingdb.library.book-acquired","traceparent":"00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01","tracestate":"vendor=value","specversion":"1.0","id":"1","time":"2026-10-13T12:00:00.123456789Z","datacontenttype":"application/json","predecessorhash":"esdb:hash:v2:84a2a7e52e0f5b0b2d26e37a291835c8f8041e9d3a1f1b06f955f50818a9e182","data":{"copies":3,"isbn":"978-0756906788","meta":{"shelf":"A\u0026B"},"nanos":9007199254740993,"price":12.5,"tags":["sf","\u003cclassic\u003e"],"title":"2001 – A Space Odyssey"},"hash":"esdb:hash:v2:f7ba6e6d8dc17c50c0dfc8663228b6aaa3345ddd8d0b70d6dcb5deb23ab34da6","signature":null}';

    // The same event as EventSourcingDB wrote it before version 2 of the hash,
    // with a hash of version 1, also taken from its test fixtures.
    private const EVENT_WITH_HASH_OF_VERSION_1 = '{"source":"tag:thenativeweb.io,2023:library","subject":"/books/42","type":"io.eventsourcingdb.library.book-acquired","traceparent":"00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01","tracestate":"vendor=value","specversion":"1.0","id":"1","time":"2026-10-13T12:00:00.123456789Z","datacontenttype":"application/json","predecessorhash":"eb04f1ac44859220f999560512a6d8067fade3c8e3cd59a5fbeb4cf30c8428f6","data":{"copies":3,"isbn":"978-0756906788","meta":{"shelf":"A\u0026B"},"nanos":9007199254740993,"price":12.5,"tags":["sf","\u003cclassic\u003e"],"title":"2001 – A Space Odyssey"},"hash":"82c1ccb26afe7554ede4478a0f5c89f9dd1f5fcf995f3602ddf149edd7aec159","signature":null}';

    // A trace parent other than the one of the events above.
    private const OTHER_TRACE_PARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    public static function eventsOfEveryHashVersion(): Iterator
    {
        yield 'version 1' => [self::EVENT_WITH_HASH_OF_VERSION_1];
        yield 'version 2' => [self::EVENT_WITH_HASH_OF_VERSION_2];
    }

    public static function callsThatReturnEvents(): Iterator
    {
        $readEvents = static fn (Client $client): iterable => $client->readEvents('/', new ReadEventsOptions(recursive: true));
        $observeEvents = static fn (Client $client): iterable => $client->observeEvents('/', new ObserveEventsOptions(recursive: true));
        $writeEvents = static fn (Client $client): iterable => $client->writeEvents([]);

        // EVENT stands for the event in the response.
        foreach (self::eventsOfEveryHashVersion() as $version => [$eventJson]) {
            yield "readEvents, {$version}" => [$readEvents, $eventJson, '{"type":"event","payload":EVENT}', 'application/x-ndjson'];
            yield "observeEvents, {$version}" => [$observeEvents, $eventJson, '{"type":"event","payload":EVENT}', 'application/x-ndjson'];
            yield "writeEvents, {$version}" => [$writeEvents, $eventJson, '[EVENT]', 'application/json'];
        }
    }

    public static function verificationErrors(): Iterator
    {
        yield 'HashVerificationFailedException' => [HashVerificationFailedException::class];
        yield 'UnsupportedHashVersionException' => [UnsupportedHashVersionException::class];
        yield 'SignatureVerificationFailedException' => [SignatureVerificationFailedException::class];
    }

    #[DataProvider('eventsOfEveryHashVersion')]
    public function testVerifiesTheHash(string $eventJson): void
    {
        $cloudEvent = $this->createEvent($eventJson);

        $this->expectNotToPerformAssertions();

        $cloudEvent->verifyHash();
    }

    #[DataProvider('eventsOfEveryHashVersion')]
    public function testFailsIfTheDataDoesNotMatchTheHash(string $eventJson): void
    {
        $cloudEvent = $this->createEvent(str_replace('"copies":3', '"copies":4', $eventJson));

        $this->expectException(HashVerificationFailedException::class);
        $this->expectExceptionMessage('Failed to verify hash.');

        $cloudEvent->verifyHash();
    }

    #[DataProvider('eventsOfEveryHashVersion')]
    public function testFailsIfTheHashIsChanged(string $eventJson): void
    {
        $cloudEvent = $this->createEvent(str_replace('f7ba6e6d', '00000000', str_replace('82c1ccb2', '00000000', $eventJson)));

        $this->expectException(HashVerificationFailedException::class);
        $this->expectExceptionMessage('Failed to verify hash.');

        $cloudEvent->verifyHash();
    }

    public function testCoversTheTraceContextWithAHashOfVersion2(): void
    {
        $cloudEvent = $this->createEvent(str_replace(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            self::OTHER_TRACE_PARENT,
            self::EVENT_WITH_HASH_OF_VERSION_2,
        ));

        $this->expectException(HashVerificationFailedException::class);

        $cloudEvent->verifyHash();
    }

    public function testDoesNotCoverTheTraceContextWithAHashOfVersion1(): void
    {
        $cloudEvent = $this->createEvent(str_replace(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            self::OTHER_TRACE_PARENT,
            self::EVENT_WITH_HASH_OF_VERSION_1,
        ));

        $this->expectNotToPerformAssertions();

        $cloudEvent->verifyHash();
    }

    public function testFailsIfTheHashIsOfAnUnknownVersion(): void
    {
        $cloudEvent = $this->createEvent(str_replace(
            'esdb:hash:v2:f7ba6e6d8dc17c50c0dfc8663228b6aaa3345ddd8d0b70d6dcb5deb23ab34da6',
            'esdb:hash:v3:f7ba6e6d8dc17c50c0dfc8663228b6aaa3345ddd8d0b70d6dcb5deb23ab34da6',
            self::EVENT_WITH_HASH_OF_VERSION_2,
        ));

        $this->expectException(UnsupportedHashVersionException::class);
        $this->expectExceptionMessage("Failed to verify hash, since its version 'v3' is not supported.");

        $cloudEvent->verifyHash();
    }

    #[DataProvider('eventsOfEveryHashVersion')]
    public function testVerifiesTheSignatureOfTheHash(string $eventJson): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $cloudEvent = $this->createEvent($eventJson, sodium_crypto_sign_secretkey($keyPair));

        $this->expectNotToPerformAssertions();

        $cloudEvent->verifySignature(sodium_crypto_sign_publickey($keyPair));
    }

    #[DataProvider('eventsOfEveryHashVersion')]
    public function testFailsIfTheSignatureDoesNotMatch(string $eventJson): void
    {
        $cloudEvent = $this->createEvent($eventJson, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

        $this->expectException(SignatureVerificationFailedException::class);
        $this->expectExceptionMessage('Signature verification failed.');

        $cloudEvent->verifySignature(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    #[DataProvider('verificationErrors')]
    public function testFailsWithErrorsThatExistingCallersCatchAsRuntimeExceptions(string $errorClass): void
    {
        $this->assertTrue(is_a($errorClass, RuntimeException::class, true));
    }

    #[DataProvider('callsThatReturnEvents')]
    public function testKeepsTheDataAsTheServerSentIt(Closure $call, string $eventJson, string $template, string $contentType): void
    {
        $line = str_replace('EVENT', $eventJson, $template);

        $address = $this->startServerWithResponses([
            $this->response([$line], interval: 0.0, holdFor: 0.0, contentType: $contentType),
        ]);
        $client = new Client("http://{$address}", 'secret');

        $events = [];
        foreach ($call($client) as $event) {
            $events[] = $event;
        }

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CloudEvent::class, $events[0]);

        $events[0]->verifyHash();
    }

    /**
     * Creates the event the way the client does from what the server sent,
     * and signs its hash if a signing key is given.
     */
    private function createEvent(string $eventJson, ?string $signingKey = null): CloudEvent
    {
        $payload = json_decode($eventJson, true, flags: JSON_THROW_ON_ERROR);

        $signature = $signingKey === null
            ? null
            : 'esdb:signature:v1:' . bin2hex(sodium_crypto_sign_detached($payload['hash'], $signingKey));

        return new CloudEvent(
            specVersion: $payload['specversion'],
            id: $payload['id'],
            time: new DateTimeImmutable($payload['time']),
            timeFromServer: $payload['time'],
            source: $payload['source'],
            subject: $payload['subject'],
            type: $payload['type'],
            dataContentType: $payload['datacontenttype'],
            data: $payload['data'],
            hash: $payload['hash'],
            predecessorHash: $payload['predecessorhash'],
            traceParent: $payload['traceparent'] ?? null,
            traceState: $payload['tracestate'] ?? null,
            signature: $signature,
            dataFromServer: RawJson::getMembers($eventJson)['data'],
        );
    }
}
