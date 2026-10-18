<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use RuntimeException;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\CloudEventTest
 */
final readonly class CloudEvent
{
    private const HASH_PREFIX = 'esdb:hash:';
    private const HASH_VERSION_2_PREFIX = self::HASH_PREFIX . 'v2:';
    private const MISSING_FIELD = '-';

    public function __construct(
        public string $specVersion,
        public string $id,
        public DateTimeImmutable $time,
        private string $timeFromServer,
        public string $source,
        public string $subject,
        public string $type,
        public string $dataContentType,
        public array $data,
        public string $hash,
        public string $predecessorHash,
        public ?string $traceParent = null,
        public ?string $traceState = null,
        public ?string $signature = null,
        private ?string $dataFromServer = null,
    ) {
    }

    public function verifyHash(): void
    {
        if (str_starts_with($this->hash, self::HASH_VERSION_2_PREFIX)) {
            $computedHash = $this->computeHashOfVersion2();
        } elseif (str_starts_with($this->hash, self::HASH_PREFIX)) {
            // A hash whose prefix names a version that is not known here
            // cannot be computed, which does not mean it was tampered with.
            [$version] = explode(':', substr($this->hash, strlen(self::HASH_PREFIX)), 2);

            throw new UnsupportedHashVersionException("Failed to verify hash, since its version '{$version}' is not supported.");
        } else {
            $computedHash = $this->computeHashOfVersion1();
        }

        if ($computedHash !== $this->hash) {
            throw new HashVerificationFailedException('Failed to verify hash.');
        }
    }

    public function verifySignature(string $verificationKey): void
    {
        if ($verificationKey === '') {
            throw new RuntimeException('Verification key must not be empty.');
        }

        if ($this->signature === null) {
            throw new RuntimeException('Signature must not be null.');
        }

        $this->verifyHash();

        $signaturePrefix = 'esdb:signature:v1:';

        if (!str_starts_with($this->signature, $signaturePrefix)) {
            throw new SignatureVerificationFailedException("Signature must start with '{$signaturePrefix}'.");
        }

        $signatureHex = substr($this->signature, strlen($signaturePrefix));
        $signatureBytes = hex2bin($signatureHex);

        if ($signatureBytes === false) {
            throw new SignatureVerificationFailedException('Failed to decode signature from hex.');
        }

        if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new SignatureVerificationFailedException(sprintf(
                'Signature has an invalid length: expected %d bytes, got %d bytes.',
                SODIUM_CRYPTO_SIGN_BYTES,
                strlen($signatureBytes),
            ));
        }

        $isSignatureValid = sodium_crypto_sign_verify_detached($signatureBytes, $this->hash, $verificationKey);

        if (!$isSignatureValid) {
            throw new SignatureVerificationFailedException('Signature verification failed.');
        }
    }

    private function computeHashOfVersion1(): string
    {
        $metadata = sprintf(
            '%s|%s|%s|%s|%s|%s|%s|%s',
            $this->specVersion,
            $this->id,
            $this->predecessorHash,
            $this->timeFromServer,
            $this->source,
            $this->subject,
            $this->type,
            $this->dataContentType,
        );

        $metadataHash = hash('sha256', $metadata);
        $dataHash = hash('sha256', $this->getDataAsReceived());

        return hash('sha256', $metadataHash . $dataHash);
    }

    private function computeHashOfVersion2(): string
    {
        $input = implode("\n", [
            self::HASH_VERSION_2_PREFIX,
            $this->specVersion,
            $this->id,
            $this->predecessorHash,
            $this->timeFromServer,
            $this->source,
            $this->subject,
            $this->type,
            $this->dataContentType,
            $this->traceParent ?? self::MISSING_FIELD,
            $this->traceState ?? self::MISSING_FIELD,
            $this->getDataAsReceived(),
        ]);

        return self::HASH_VERSION_2_PREFIX . hash('sha256', $input);
    }

    private function getDataAsReceived(): string
    {
        // The server hashes the data as it sends it, so the data is hashed as
        // it was received. Only an event that was not received from the server
        // lacks it, so its data is encoded again instead.
        if ($this->dataFromServer !== null) {
            return $this->dataFromServer;
        }

        $dataJson = json_encode($this->data);
        if ($dataJson === false) {
            throw new RuntimeException('Failed to encode data to JSON.');
        }

        return $dataJson;
    }
}
