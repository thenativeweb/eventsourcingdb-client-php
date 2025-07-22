<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use InvalidArgumentException;

class Response
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
        418 => "I'm a teapot",
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
    private array $headers = [];

    public function __construct(
        private readonly int $statusCode = 200,
        array $headers = [],
        private readonly ?Stream $stream = null,
        private readonly string $protocolVersion = '1.1',
    ) {
        if (!array_key_exists($this->statusCode, self::PHRASES)) {
            throw new InvalidArgumentException("Internal HttpClient: The status code {$this->statusCode} must be one of the defined HTTP status codes.");
        }

        $this->parseHeaders($headers);
    }

    public function parseHeaders(array $headers): void
    {
        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }

            $headerName = $header;
            $headerValue = '';

            if (str_contains($header, ':')) {
                [$headerName, $headerValue] = explode(':', $header, 2);
            }

            $headerName = trim($headerName);
            $headerValue = trim($headerValue);

            $lowerName = strtolower($headerName);
            $this->headerNames[$lowerName] = $headerName;
            $this->headers[$headerName] = $headerValue;
        }
    }

    public function getStream(): Stream
    {
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

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getHeaders(): array
    {
        $header = [];

        foreach (array_keys($this->headers) as $headerName) {
            $header[] = $this->getHeaderLine($headerName);
        }

        return $header;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headerNames);
    }

    public function getHeader(string $name): array
    {
        $lowerName = strtolower($name);
        if (!array_key_exists($lowerName, $this->headerNames)) {
            return [];
        }

        $name = $this->headerNames[$lowerName];
        return [
            $this->headers[$name],
        ];
    }

    public function getHeaderLine(string $name): string
    {
        $lowerName = strtolower($name);
        if (!array_key_exists($lowerName, $this->headerNames)) {
            return '';
        }

        $headerName = $this->headerNames[$lowerName];
        return $headerName . ': ' . $this->headers[$headerName];
    }
}
