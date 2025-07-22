<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Uri;

final class UriTest extends TestCase
{
    public function testItParsesFullUri(): void
    {
        $uriString = 'https://user:pass@example.com:8080/path/to/resource?query=123#section';
        $uri = new Uri($uriString);

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8080, $uri->getPort());
        $this->assertSame('user:pass', $uri->getUserInfo());
        $this->assertSame('/path/to/resource', $uri->getPath());
        $this->assertSame('query=123', $uri->getQuery());
        $this->assertSame('section', $uri->getFragment());
        $this->assertSame('user:pass@example.com:8080', $uri->getAuthority());
        $this->assertSame($uriString, (string) $uri);
    }

    public function testItParsesUriWithoutUserInfoPortQueryFragment(): void
    {
        $uriString = 'http://example.com/';
        $uri = new Uri($uriString);

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertNull($uri->getPort());
        $this->assertSame('', $uri->getUserInfo());
        $this->assertSame('/', $uri->getPath());
        $this->assertSame('', $uri->getQuery());
        $this->assertSame('', $uri->getFragment());
        $this->assertSame('example.com', $uri->getAuthority());
        $this->assertSame($uriString, (string) $uri);
    }

    public function testItUsesDefaultHttpSchemeIfMissing(): void
    {
        $uriString = 'example.com/path';
        $uri = new Uri($uriString);

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('/path', $uri->getPath());
    }

    public function testItParsesUriWithOnlyHost(): void
    {
        $uriString = 'http://example.com';
        $uri = new Uri($uriString);

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('/', $uri->getPath());
        $this->assertSame($uriString . $uri->getPath(), (string) $uri);
    }
}
