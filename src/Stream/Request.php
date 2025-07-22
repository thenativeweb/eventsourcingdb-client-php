<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

class Request
{
    private Uri $uri;

    public function __construct(
        private string $method,
        string $uri,
        private array $headers = [],
        private ?string $body = null,
        private string $protocolVersion = '1.1'
    ) {
        $this->uri = new Uri($uri);
    }

    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }
}
