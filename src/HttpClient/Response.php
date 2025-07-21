<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;
use Psr\Http\Message\MessageInterface as PsrMessageInterface;

class Response implements ResponseInterface
{
    private const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    private array $headerNames = [];

    public function __construct(
        private int $statusCode = 200,
        private array $headers = [],
        private null|StreamInterface|PsrStreamInterface $stream = null,
        private string $protocolVersion = '1.1',
    ) {
        if (!array_key_exists($this->statusCode, self::PHRASES)) {
            throw new \InvalidArgumentException("The status code {$this->statusCode} must be one of the defined HTTP status codes.");
        }
    }

    public function getStream(): StreamInterface
    {
        if (!$this->stream instanceof StreamInterface) {
            throw new \RuntimeException('The body must be an instance of Thenativeweb/ResponseStream to be converted to a string, but you provided ' . get_class($this->stream));
        }

        return $this->stream;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return self::PHRASES[$this->statusCode];
    }

    public function withStatus(int $code, string $reasonPhrase = ''): PsrResponseInterface
    {
        if (!in_array($code, array_keys(self::PHRASES), true)) {
            throw new \InvalidArgumentException("The status code {$code} must be one of the defined HTTP status codes.");
        }

        $response = clone $this;
        $response->statusCode = $code;

        return $response;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): PsrMessageInterface
    {
        if ($this->protocolVersion === $version) {
            return $this;
        }

        $repsonse = clone $this;
        $repsonse->protocolVersion = $version;

        return $repsonse;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $name = strtolower($name);

        if (!isset($this->headerNames[$name])) {
            return [];
        }

        $name = $this->headerNames[$name];

        return $this->headers[$name];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): PsrMessageInterface
    {
        $normalized = strtolower($name);
        $headerName = $response->headerNames[$normalized] ?? null;

        $response = clone $this;
        if ($headerName !== null) {
            unset($response->headers[$headerName]);
        }
        $response->headerNames[$normalized] = $name;
        $response->headers[$name] = $value;

        return $response;
    }

    public function withAddedHeader(string $name, $value): PsrMessageInterface
    {
        $normalized = strtolower($name);

        $response = clone $this;
        if (isset($response->headerNames[$normalized])) {
            $name = $this->headerNames[$normalized];
            $response->headers[$name] = array_merge($this->headers[$name], $value);

            return $response;
        }

        $response->headerNames[$normalized] = $name;
        $response->headers[$name] = $value;

        return $response;
    }

    public function withoutHeader(string $name): PsrMessageInterface
    {
        $normalized = strtolower($name);

        if (!isset($this->headerNames[$normalized])) {
            return $this;
        }

        $name = $this->headerNames[$normalized];

        $response = clone $this;
        unset($response->headers[$name], $response->headerNames[$normalized]);

        return $response;
    }

    public function getBody(): PsrStreamInterface
    {
        if (!$this->stream instanceof PsrStreamInterface) {
            throw new \RuntimeException('The body must be an instance of Psr7/StreamInterface.');
        }

        return $this->stream;
    }

    public function withBody(PsrStreamInterface $body): PsrMessageInterface
    {
        if ($body === $this->stream) {
            return $this;
        }

        $response = clone $this;
        $response->stream = $body;

        return $response;
    }
}
