<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use InvalidArgumentException;
use Stringable;

class Uri implements Stringable
{
    private array $parseUrl;

    public function __construct(string $uri)
    {
        $this->parseUrl = $this->parseUrl($uri);
    }

    public function __toString(): string
    {
        $uri = $this->getScheme() . '://';

        if ($this->getUserInfo() !== '') {
            $uri .= $this->getUserInfo() . '@';
        }

        $uri .= $this->getHost();

        if ($this->getPort()) {
            $uri .= ':' . $this->getPort();
        }

        $uri .= $this->getPath();

        if ($this->getQuery() !== '') {
            $uri .= '?' . $this->getQuery();
        }

        if ($this->getFragment() !== '') {
            $uri .= '#' . $this->getFragment();
        }

        return $uri;
    }

    public function parseUrl(string $uri): array
    {
        if (!str_starts_with($uri, 'http') && !str_starts_with($uri, '//')) {
            $uri = '//' . $uri;
        }

        $parsedUrl = parse_url($uri);
        if ($parsedUrl === false) {
            throw new InvalidArgumentException('Internal HttpClient: Invalid URI: ' . $uri);
        }

        return $parsedUrl;
    }

    public function getScheme(): string
    {
        return $this->parseUrl['scheme'] ?? 'http';
    }

    public function getAuthority(): string
    {
        $authority = $this->getHost();
        $port = $this->getPort();
        $userInfo = $this->getUserInfo();

        if ($userInfo !== '') {
            $authority = $userInfo . '@' . $authority;
        }

        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        $user = $this->parseUrl['user'] ?? null;
        $pass = $this->parseUrl['pass'] ?? null;

        if ($user === null && $pass === null) {
            return '';
        }

        if ($pass !== null) {
            return $user . ':' . $pass;
        }

        return $user;
    }

    public function getHost(): string
    {
        return $this->parseUrl['host'] ?? '';
    }

    public function getPort(): ?int
    {
        return $this->parseUrl['port'] ?? null;
    }

    public function getPath(): string
    {
        return $this->parseUrl['path'] ?? '/';
    }

    public function getQuery(): string
    {
        return $this->parseUrl['query'] ?? '';
    }

    public function getFragment(): string
    {
        return $this->parseUrl['fragment'] ?? '';
    }
}
