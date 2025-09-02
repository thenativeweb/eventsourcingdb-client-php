<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\TestContainer;

use Testcontainers\Container\HttpMethod;
use Testcontainers\Container\StartedTestContainer;
use Testcontainers\Exception\ContainerWaitingTimeoutException;
use Testcontainers\Wait\BaseWaitStrategy;

class WaitForHttp extends BaseWaitStrategy
{
    protected HttpMethod $method = HttpMethod::GET;

    protected string $path = '/';

    protected string $protocol = 'http';

    protected int $expectedStatusCode = 200;

    protected bool $allowInsecure = false;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * @var int Timeout in milliseconds for reading the response
     */
    protected int $readTimeout = 1000;

    public function __construct(
        protected int $port,
        int $timeout = 10000,
        int $pollInterval = 500
    ) {
        parent::__construct($timeout, $pollInterval);
    }

    /**
     * @param HttpMethod|value-of<HttpMethod> $method
     */
    public function withMethod(HttpMethod | string $method): self
    {
        if (is_string($method)) {
            $method = HttpMethod::fromString($method);
        }
        $this->method = $method;
        return $this;
    }

    public function withPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function withExpectedStatusCode(int $statusCode): self
    {
        $this->expectedStatusCode = $statusCode;
        return $this;
    }

    public function usingHttps(): self
    {
        $this->protocol = 'https';
        return $this;
    }

    public function allowInsecure(): self
    {
        $this->allowInsecure = true;
        return $this;
    }

    public function withReadTimeout(int $timeout): self
    {
        $this->readTimeout = $timeout;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function wait(StartedTestContainer $startedTestContainer): void
    {
        $startTime = microtime(true) * 1000;

        while (true) {
            $elapsedTime = (microtime(true) * 1000) - $startTime;

            if ($elapsedTime > $this->timeout) {
                throw new ContainerWaitingTimeoutException($startedTestContainer->getId());
            }

            $containerAddress = $startedTestContainer->getHost();

            $storage = iterator_to_array($startedTestContainer->getBoundPorts());
            $port = array_key_exists($this->port . '/tcp', $storage) ? $storage[$this->port . '/tcp'][0]->getHostPort() : $this->port;

            $url = sprintf('%s://%s:%d%s', $this->protocol, $containerAddress, $port, $this->path);
            $responseCode = $this->makeHttpRequest($url);

            if ($responseCode === $this->expectedStatusCode) {
                return;
            }

            usleep($this->pollInterval * 1000);
        }
    }

    private function makeHttpRequest(string $url): int
    {
        if ($url === '' || $url === '0') {
            return 0;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method->value);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->readTimeout);

        if ($this->allowInsecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if ($this->headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(static fn ($k, $v): string => "{$k}: {$v}", array_keys($this->headers), $this->headers));
        }

        curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $responseCode;
    }
}
