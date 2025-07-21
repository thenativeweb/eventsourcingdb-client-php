<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

use Psr\Http\Message\UriInterface;

class Uri implements UriInterface, \Stringable
{
    private array $parseUrl;

    public function __construct(string $uri) {
        $this->parseUrl = $this->parseUrl($uri);
    }

    public function parseUrl(string $uri): array
    {
        $parsedUrl = parse_url($uri);
        if ($parsedUrl === false) {
            throw new \InvalidArgumentException('Invalid URI: ' . $uri);
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
            return $user . ':' .$pass;
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

    public function withScheme(string $scheme): UriInterface
    {
        if ($this->host === $host) {
            return $this;
        }
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $userInfo = $user;
        if ($password !== null) {
            $userInfo .= ':' . $password;
        }

        if ($this->getUserInfo() === $userInfo) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['user'] = $user;
        $uri->parseUrl['pass'] = $password;

        return $uri;
    }

    public function withHost(string $host): UriInterface
    {
        if ($this->getHost() === $host) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['host'] = $host;

        return $uri;
    }

    public function withPort(?int $port): UriInterface
    {
        if ($this->getPort() === $port) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['port'] = $port;

        return $uri;
    }

    public function withPath(string $path): UriInterface
    {
        if ($this->getPath() === $path) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['path'] = $path;

        return $uri;
    }

    public function withQuery(string $query): UriInterface
    {
        if ($this->getQuery() === $query) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['query'] = $query;

        return $uri;
    }

    public function withFragment(string $fragment): UriInterface
    {
        if ($this->getFragment() === $fragment) {
            return $this;
        }

        $uri = clone $this;
        $uri->parseUrl['fragment'] = $fragment;

        return $uri;
    }

    public function __toString(): string
    {
        $uri = $this->getScheme() . '://';

        if ($this->getUserInfo()) {
            $uri .= $this->getUserInfo() . '@';
        }

        $uri .= $this->getHost();

        if ($this->getPort()) {
            $uri .= ':' . $this->getPort();
        }

        $uri .= $this->getPath();

        if ($this->getQuery()) {
            $uri .= '?' . $this->getQuery();
        }

        if ($this->getFragment()) {
            $uri .= '#' . $this->getFragment();
        }

        return $uri;
    }
}
