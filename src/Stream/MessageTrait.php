<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

trait MessageTrait
{
    private array $headerNames = [];
    private array $headers = [];

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
            $headerValues = null;

            if (str_contains($headerValue, ',')) {
                $headerValues = explode(',', $headerValue);
                $headerValues = array_map('trim', $headerValues);
            }

            if ($headerValues === null) {
                $headerValues = [$headerValue];
            }

            $lowerName = strtolower($headerName);
            $this->headerNames[$lowerName] = $headerName;
            $this->headers[$headerName] = $headerValues;
        }
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
        return $this->headers[$name];
    }

    public function getHeaderLine(string $name): string
    {
        $lowerName = strtolower($name);
        if (!array_key_exists($lowerName, $this->headerNames)) {
            return '';
        }

        $headerName = $this->headerNames[$lowerName];
        return $headerName . ': ' . implode(', ', $this->headers[$headerName]);
    }
}
