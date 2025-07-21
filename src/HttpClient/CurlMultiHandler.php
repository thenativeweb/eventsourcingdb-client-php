<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class CurlMultiHandler
{
    private ?\CurlHandle $handle = null;
    private ?\CurlMultiHandle $multiHandle = null;
    private float $streamTimeout = 0.0;
    public BufferQueue $header;

    public BufferQueue $write;

    public function __construct(
    ) {
        $this->header = new BufferQueue();
        $this->write = new BufferQueue();
    }

    public function setStreamTimeout(float $timeout): void
    {
        $this->streamTimeout = $timeout;
    }

    public function addHandle(RequestInterface $request): void
    {
        $handle = curl_init();

        $options = CurlFactory::create(
            $request,
            $this->header,
            $this->write,
        );

        if (!curl_setopt_array($handle, $options)) {
            throw new \RuntimeException('Failed to set cURL options: ' . curl_error($handle));
        }

        $this->handle = $handle;
    }

    public function execute(): void
    {
        if ($this->handle === null) {
            throw new \RuntimeException('No handle to execute.');
        }

        $multiHandle = curl_multi_init();
        if (curl_multi_add_handle($multiHandle, $this->handle) !== CURLM_OK) {
            throw new \RuntimeException('Failed to add cURL handle to multi handle: ' . curl_multi_strerror(curl_multi_errno($multiHandle)));
        }

        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle);
            }
        } while ($this->header->isEmpty() && $running && $status === CURLM_OK);

        $this->multiHandle = $multiHandle;
    }

    public function startIterator(): iterable
    {
        if ($this->multiHandle === null) {
            throw new \RuntimeException('No multi handle to execute.');
        }

        $start = microtime(true);

        do {
            if ($this->streamTimeout > 0 && (microtime(true) - $start) >= $this->streamTimeout) {
                break;
            }

            $status = curl_multi_exec($this->multiHandle, $running);
            if ($running) {
                curl_multi_select($this->multiHandle);
            }

            while (!$this->write->isEmpty()) {
                yield $this->write->read();
            }
        } while ($running && $status === CURLM_OK);

        curl_multi_remove_handle($this->multiHandle, $this->handle);
        curl_close($this->handle);
        curl_multi_close($this->multiHandle);
    }
}
