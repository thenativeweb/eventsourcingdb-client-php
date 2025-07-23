<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;

class CurlMultiHandler
{
    private ?CurlHandle $handle = null;
    private ?CurlMultiHandle $multiHandle = null;
    private float $abortInSecond = 0.0;
    private float $iteratorTime;
    private ?Queue $header = null;
    private ?Queue $write = null;

    public function abortIn(float $second): void
    {
        $this->abortInSecond = abs($second);
        $this->iteratorTime = microtime(true);
    }

    public function getHeaderQueue(): Queue
    {
        if (!$this->header instanceof Queue) {
            throw new RuntimeException('Internal HttpClient: No header queue available.');
        }

        return $this->header;
    }

    public function getWriteQueue(): Queue
    {
        if (!$this->write instanceof Queue) {
            throw new RuntimeException('Internal HttpClient: No write queue available.');
        }

        return $this->write;
    }

    public function addHandle(Request $request): void
    {
        $handle = curl_init();

        $this->header = new Queue(maxSize: 100);
        $this->write = new Queue();

        $options = CurlFactory::create(
            $request,
            $this->header,
            $this->write,
        );

        if (!curl_setopt_array($handle, $options)) {
            throw new RuntimeException('Internal HttpClient: Failed to set cURL options: ' . curl_error($handle));
        }

        $this->handle = $handle;
    }

    public function execute(): void
    {
        if (!$this->handle instanceof CurlHandle) {
            throw new RuntimeException('Internal HttpClient: No handle to execute.');
        }

        if (!$this->header instanceof Queue) {
            throw new RuntimeException('Internal HttpClient: No header queue available.');
        }

        $multiHandle = curl_multi_init();
        if (curl_multi_add_handle($multiHandle, $this->handle) !== CURLM_OK) {
            throw new RuntimeException('Internal HttpClient: Failed to add cURL handle to multi handle: ' . curl_multi_strerror(curl_multi_errno($multiHandle)));
        }

        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle);
            }
        } while ($this->header->isEmpty() && $running && $status === CURLM_OK);

        $this->multiHandle = $multiHandle;
    }

    public function contentIterator(): iterable
    {
        if (!$this->multiHandle instanceof CurlMultiHandle) {
            throw new RuntimeException('Internal HttpClient: No multi handle to execute.');
        }

        if (!$this->write instanceof Queue) {
            throw new RuntimeException('Internal HttpClient: No write queue available.');
        }

        $this->iteratorTime = microtime(true);

        do {
            if (
                $this->abortInSecond > 0
                && (microtime(true) - $this->iteratorTime) >= $this->abortInSecond
            ) {
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
        curl_multi_close($this->multiHandle);
        curl_close($this->handle);

        unset(
            $this->handle,
            $this->multiHandle,
            $this->header,
            $this->write,
        );

        $this->handle = null;
        $this->multiHandle = null;
        $this->header = null;
        $this->write = null;
    }
}
