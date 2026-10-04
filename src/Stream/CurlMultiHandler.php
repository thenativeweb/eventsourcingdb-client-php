<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\HeartbeatTimeoutException;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\CurlMultiHandlerTest
 */
class CurlMultiHandler
{
    private const HEARTBEAT_TIMEOUT = 30.0;

    private ?CurlHandle $curlHandle = null;
    private ?CurlMultiHandle $curlMultiHandle = null;
    private float $abortIn = 0.0;
    private ?CurlHandle $abortInCurlHandle = null;
    private float $iteratorTime;
    private float $heartbeatTimeout = self::HEARTBEAT_TIMEOUT;
    private ?Queue $header = null;
    private ?Queue $write = null;

    /**
     * @var array<int, CurlHandle>
     */
    private array $readingCurlHandles = [];

    public function abortIn(float $seconds): void
    {
        $this->abortIn = max($seconds, 0.0);
        $this->iteratorTime = microtime(true);

        // The abort time applies to the stream being read, or, if there is
        // none, to the next request.
        $this->abortInCurlHandle = end($this->readingCurlHandles) ?: null;
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

        // An abort time set while no stream was being read applies to this
        // request.
        if (!$this->abortInCurlHandle instanceof CurlHandle) {
            $this->abortInCurlHandle = $curlHandle;
        }
    }

    public function execute(): void
    {
        $curlHandle = $this->curlHandle();
        $queue = $this->getHeaderQueue();

        $curlMultiHandle = curl_multi_init();
        $this->curlMultiHandle = $curlMultiHandle;

        if (curl_multi_add_handle($curlMultiHandle, $curlHandle) !== CURLM_OK) {
            throw new RuntimeException('Internal HttpClient: Failed to add cURL handle to multi handle: ' . curl_multi_strerror(curl_multi_errno($curlMultiHandle)));
        }

        // The headers may arrive in several packets, so this waits for all of
        // them, not only for the first line.
        do {
            $status = curl_multi_exec($curlMultiHandle, $isRunning);
            if (!$queue->isComplete() && $isRunning) {
                curl_multi_select($curlMultiHandle);
            }

            $this->verifyCurlHandle($curlMultiHandle);

        } while (!$queue->isComplete() && $isRunning && $status === CURLM_OK);
    }

    public function close(): void
    {
        $curlHandle = $this->curlHandle;

        // The response of a request that is being read is closed once the
        // reading ends.
        if (!$curlHandle instanceof CurlHandle || isset($this->readingCurlHandles[spl_object_id($curlHandle)])) {
            return;
        }

        $this->closeHandles($curlHandle, $this->curlMultiHandle);
    }

    public function contentIterator(bool $withHeartbeatTimeout = false): iterable
    {
        $curlHandle = $this->curlHandle();
        $curlMultiHandle = $this->curlMultiHandle();
        $queue = $this->getWriteQueue();

        $heartbeatTimeout = $withHeartbeatTimeout ? $this->heartbeatTimeout : INF;

        if ($this->abortInCurlHandle === $curlHandle) {
            $this->iteratorTime = microtime(true);
        }

        $lineTime = microtime(true);

        $this->readingCurlHandles[spl_object_id($curlHandle)] = $curlHandle;

        // The finally block also runs when the caller stops reading early and
        // the generator is destroyed, so the connection does not stay open.
        try {
            do {
                if (
                    $this->abortInCurlHandle === $curlHandle
                    && $this->abortIn > 0
                    && (microtime(true) - $this->iteratorTime) >= $this->abortIn
                ) {
                    break;
                }

                $status = curl_multi_exec($curlMultiHandle, $isRunning);
                if (!$queue->isEmpty()) {
                    $lineTime = microtime(true);
                } elseif ($isRunning && (microtime(true) - $lineTime) >= $heartbeatTimeout) {
                    throw new HeartbeatTimeoutException("No event and no heartbeat arrived for {$heartbeatTimeout} seconds.");
                } elseif ($isRunning) {
                    curl_multi_select($curlMultiHandle, max(0.0, min(1.0, $lineTime + $heartbeatTimeout - microtime(true))));
                }

                $this->verifyCurlHandle($curlMultiHandle);

                while (!$queue->isEmpty()) {
                    yield $queue->read();
                }
            } while ($isRunning && $status === CURLM_OK);
        } finally {
            unset($this->readingCurlHandles[spl_object_id($curlHandle)]);

            $this->closeHandles($curlHandle, $curlMultiHandle);
        }
    }

    private function closeHandles(CurlHandle $curlHandle, ?CurlMultiHandle $curlMultiHandle): void
    {
        if ($this->abortInCurlHandle === $curlHandle) {
            $this->abortIn = 0.0;
            $this->abortInCurlHandle = null;
        }

        if ($curlMultiHandle instanceof CurlMultiHandle) {
            curl_multi_remove_handle($curlMultiHandle, $curlHandle);
            curl_multi_close($curlMultiHandle);
        }

        // The handler may already belong to a later request, for example if
        // the caller releases a stream only after starting the next one.
        if ($this->curlHandle !== $curlHandle) {
            return;
        }

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
