<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

class Request
{
    use MessageTrait;

    private Uri $uri;

    public function __construct(
        private readonly string $method,
        string $uri,
        array $headers = [],
        private readonly ?string $body = null,
        private readonly string $protocolVersion = '1.1'
    ) {
        $this->uri = new Uri($uri);
        $this->parseHeaders($headers);
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

    public function getBody(): ?string
    {
        return $this->body;
    }
}
