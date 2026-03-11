<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

final readonly class Ed25519
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {
    }
}
