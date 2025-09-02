<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use DateTimeImmutable;
use RuntimeException;

final readonly class CloudEvent
{
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
    ) {
    }

    public function verifyHash(): void
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

        $dataJson = json_encode($this->data);
        if ($dataJson === false) {
            throw new RuntimeException('Failed to encode data to JSON.');
        }

        $metadataHash = hash('sha256', $metadata);
        $dataHash = hash('sha256', $dataJson);
        $finalHash = hash('sha256', $metadataHash . $dataHash);

        if ($finalHash !== $this->hash) {
            throw new RuntimeException('Failed to verify hash.');
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
            throw new RuntimeException("Signature must start with '{$signaturePrefix}'");
        }

        $signatureHex = substr($this->signature, strlen($signaturePrefix));
        $signatureBytes = hex2bin($signatureHex);

        if ($signatureBytes === false) {
            throw new RuntimeException('Failed to decode signature from hex.');
        }

        if ($signatureBytes === '') {
            throw new RuntimeException('Signature cannot be empty.');
        }

        $isSignatureValid = sodium_crypto_sign_verify_detached($signatureBytes, $this->hash, $verificationKey);

        if (!$isSignatureValid) {
            throw new RuntimeException('Signature verification failed.');
        }
    }
}
