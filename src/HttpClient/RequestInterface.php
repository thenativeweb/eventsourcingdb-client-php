<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

use Psr\Http\Message\UriInterface;

interface RequestInterface
{
    public function getMethod(): string;
    public function getUri(): UriInterface;
    public function getProtocolVersion(): string;
    public function getHeaders(): array;
    public function getBody(): ?string;
}
