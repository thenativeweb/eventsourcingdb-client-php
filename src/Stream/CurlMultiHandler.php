<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;

class CurlMultiHandler
{
    private ?CurlHandle $curlHandle = null;
    private ?CurlMultiHandle $curlMultiHandle = null;
    private float $abortIn = 0.0;
    private float $iteratorTime;
    private ?Queue $header = null;
    private ?Queue $write = null;

    public function abortIn(float $seconds): void
    {
        $this->abortIn = max($seconds, 0.0);
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
        $curlHandle = curl_init();

        $this->header = new Queue(maxSize: 100);
        $this->write = new Queue();

        $options = CurlFactory::create(
            $request,
            $this->header,
            $this->write,
        );

        if (!curl_setopt_array($curlHandle, $options)) {
            throw new RuntimeException('Internal HttpClient: Failed to set cURL options: ' . curl_error($curlHandle));
        }

        $this->curlHandle = $curlHandle;
    }

    public function execute(): void
    {
        $curlHandle = $this->curlHandle();
        $queue = $this->getHeaderQueue();

        $curlMultiHandle = curl_multi_init();
        if (curl_multi_add_handle($curlMultiHandle, $curlHandle) !== CURLM_OK) {
            throw new RuntimeException('Internal HttpClient: Failed to add cURL handle to multi handle: ' . curl_multi_strerror(curl_multi_errno($curlMultiHandle)));
        }

        do {
            $status = curl_multi_exec($curlMultiHandle, $isRunning);
            if ($isRunning) {
                curl_multi_select($curlMultiHandle);
            }

            $this->verifyCurlHandle($curlMultiHandle);

        } while ($queue->isEmpty() && $isRunning && $status === CURLM_OK);

        $this->curlMultiHandle = $curlMultiHandle;
    }

    public function contentIterator(): iterable
    {
        $curlHandle = $this->curlHandle();
        $curlMultiHandle = $this->curlMultiHandle();
        $queue = $this->getWriteQueue();

        $this->iteratorTime = microtime(true);

        do {
            if (
                $this->abortIn > 0
                && (microtime(true) - $this->iteratorTime) >= $this->abortIn
            ) {
                break;
            }

            $status = curl_multi_exec($curlMultiHandle, $isRunning);
            if ($isRunning) {
                curl_multi_select($curlMultiHandle);
            }

            $this->verifyCurlHandle($curlMultiHandle);

            while (!$queue->isEmpty()) {
                yield $queue->read();
            }
        } while ($isRunning && $status === CURLM_OK);

        curl_multi_remove_handle($curlMultiHandle, $curlHandle);
        curl_multi_close($curlMultiHandle);
        curl_close($curlHandle);

        unset(
            $this->curlHandle,
            $this->curlMultiHandle,
            $this->header,
            $this->write,
        );

        $this->curlHandle = null;
        $this->curlMultiHandle = null;
        $this->header = null;
        $this->write = null;
    }

    private function verifyCurlHandle(CurlMultiHandle $curlMultiHandle): void
    {
        $info = curl_multi_info_read($curlMultiHandle);
        if ($info === false) {
            return;
        }

        $curlHandle = $info['handle'] ?? null;
        if (!$curlHandle instanceof CurlHandle) {
            throw new RuntimeException('Internal HttpClient: cURL handle info read returned an invalid handle.');
        }

        if (curl_errno($curlHandle) !== 0) {
            throw new RuntimeException('Internal HttpClient: cURL handle execution failed with error: ' . curl_error($curlHandle));
        }
    }

    private function curlHandle(): CurlHandle
    {
        if (!$this->curlHandle instanceof CurlHandle) {
            throw new RuntimeException('Internal HttpClient: No handle available.');
        }

        return $this->curlHandle;
    }

    private function curlMultiHandle(): CurlMultiHandle
    {
        if (!$this->curlMultiHandle instanceof CurlMultiHandle) {
            throw new RuntimeException('Internal HttpClient: No multi handle available.');
        }

        return $this->curlMultiHandle;
    }
}
