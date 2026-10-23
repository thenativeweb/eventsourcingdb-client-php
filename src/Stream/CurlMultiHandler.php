<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;
use CurlMultiHandle;
use InvalidArgumentException;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\ConnectionStalledException;
use Thenativeweb\Eventsourcingdb\RequestAbortedException;

/**
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\CurlMultiHandlerTest
 */
class CurlMultiHandler
{
    public const DEFAULT_STALL_TIMEOUT = 30.0;

    private ?CurlHandle $curlHandle = null;
    private ?CurlMultiHandle $curlMultiHandle = null;
    private ?float $abortDeadline = null;
    private ?CurlHandle $abortInCurlHandle = null;
    private ?Queue $header = null;
    private ?Queue $write = null;
    private ?StallClock $stallClock = null;
    private bool $hasLastStreamBeenAborted = false;

    /**
     * @var array<int, CurlHandle>
     */
    private array $readingCurlHandles = [];

    /**
     * @param float $stallTimeout the seconds after which a request fails if nothing at all has arrived from the server
     */
    public function __construct(
        private readonly float $stallTimeout = self::DEFAULT_STALL_TIMEOUT,
    ) {
        if (!is_finite($stallTimeout) || $stallTimeout <= 0) {
            throw new InvalidArgumentException(sprintf(
                'The stall timeout must be a positive number of seconds, got %s.',
                var_export($stallTimeout, true),
            ));
        }
    }

    /**
     * Aborts the stream being read, or, if there is none, the next request,
     * once the given seconds, counted from the call, are over. It is a point
     * in time rather than a duration, so that it bounds the whole request,
     * from connecting to reading the answer. An abort time of zero or less
     * sets none.
     */
    public function abortIn(float $seconds): void
    {
        $this->abortDeadline = $seconds > 0 ? microtime(true) + $seconds : null;

        // The abort time applies to the stream being read, or, if there is
        // none, to the next request.
        $this->abortInCurlHandle = end($this->readingCurlHandles) ?: null;
    }

    /**
     * Drops an abort time that waits for the next request, for a request that
     * fails before it is sent, so that the abort time does not apply to a
     * later request instead. An abort time that applies to a stream being read
     * stays, since a request made while reading a stream never takes it over.
     */
    public function useUpAbortTimeOfNextRequest(): void
    {
        if (!$this->abortInCurlHandle instanceof CurlHandle) {
            $this->abortDeadline = null;
        }
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

    public function getStallClock(): StallClock
    {
        if (!$this->stallClock instanceof StallClock) {
            throw new RuntimeException('Internal HttpClient: No stall clock available.');
        }

        return $this->stallClock;
    }

    public function addHandle(Request $request): void
    {
        $curlHandle = curl_init();

        $this->header = new Queue(maxSize: 100);
        $this->write = new Queue();
        $this->stallClock = new StallClock($this->stallTimeout);

        $options = CurlFactory::create(
            $request,
            $this->header,
            $this->write,
            stallClock: $this->stallClock,
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
        $stallClock = $this->getStallClock();

        // No code of the caller runs while the headers are awaited, so the
        // abort time cannot change meanwhile.
        $abortDeadline = $this->getAbortDeadline($curlHandle);

        $curlMultiHandle = curl_multi_init();
        $this->curlMultiHandle = $curlMultiHandle;

        if (curl_multi_add_handle($curlMultiHandle, $curlHandle) !== CURLM_OK) {
            throw new RuntimeException('Internal HttpClient: Failed to add cURL handle to multi handle: ' . curl_multi_strerror(curl_multi_errno($curlMultiHandle)));
        }

        // The stall timeout counts from sending the request, and the abort
        // time from the call to abortIn, so both also bound connecting and
        // waiting for the headers.
        $stallClock->reset();

        // The headers may arrive in several packets, so this waits for all of
        // them, not only for the first line.
        do {
            // The abort time is checked before cURL goes on with the request,
            // so that a request whose abort time is over before it starts is
            // not sent at all.
            if ($this->isAbortTimeOver($abortDeadline)) {
                throw new RequestAbortedException();
            }

            $status = curl_multi_exec($curlMultiHandle, $isRunning);
            if (!$queue->isComplete() && $isRunning) {
                if ($stallClock->hasStalled()) {
                    throw $this->createConnectionStalledException($stallClock);
                }

                curl_multi_select($curlMultiHandle, $this->selectTimeout($stallClock->getDeadline(), $abortDeadline));
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

    /**
     * Tells whether the request being sent got as far as a connection to the
     * server, including a TLS handshake for https, so that a failure can be
     * told apart from one that happened before anything was sent. cURL
     * records the time until the transfer starts, or the size of the request
     * it has sent, only once the connection is established.
     */
    public function hasEstablishedConnection(): bool
    {
        if (!$this->curlHandle instanceof CurlHandle) {
            return false;
        }

        return curl_getinfo($this->curlHandle, CURLINFO_PRETRANSFER_TIME_T) > 0
            || curl_getinfo($this->curlHandle, CURLINFO_REQUEST_SIZE) > 0;
    }

    /**
     * Tells whether the stream that was read last ended because the time that
     * abortIn set was over, rather than because the server ended it, so that
     * an aborted stream can be told apart from one that broke off.
     */
    public function hasLastStreamBeenAborted(): bool
    {
        return $this->hasLastStreamBeenAborted;
    }

    /**
     * Yields the body as it arrives. If nothing arrives for the stall timeout,
     * it throws a ConnectionStalledException, and once the abort time of the
     * request is over, it ends.
     */
    public function contentIterator(): iterable
    {
        $curlHandle = $this->curlHandle();
        $curlMultiHandle = $this->curlMultiHandle();
        $queue = $this->getWriteQueue();
        $stallClock = $this->getStallClock();

        $this->readingCurlHandles[spl_object_id($curlHandle)] = $curlHandle;
        $isAborted = false;

        // The finally block also runs when the caller stops reading early and
        // the generator is destroyed, so the connection does not stay open.
        try {
            do {
                // The abort time is read anew on every turn, since abortIn may
                // set it within the loop that reads the stream.
                if ($this->isAbortTimeOver($this->getAbortDeadline($curlHandle))) {
                    $isAborted = true;

                    break;
                }

                $status = curl_multi_exec($curlMultiHandle, $isRunning);
                if ($queue->isEmpty() && $isRunning) {
                    if ($stallClock->hasStalled()) {
                        throw $this->createConnectionStalledException($stallClock);
                    }

                    // Waiting for data ends at the stall deadline, or earlier at
                    // the abort time of the stream being read.
                    curl_multi_select($curlMultiHandle, $this->selectTimeout($stallClock->getDeadline(), $this->getAbortDeadline($curlHandle)));
                }

                $this->verifyCurlHandle($curlMultiHandle);

                while (!$queue->isEmpty()) {
                    yield $queue->read();
                }
            } while ($isRunning && $status === CURLM_OK);
        } finally {
            // This is set last, so that a stream read within the loop of this
            // one does not leave its own outcome behind.
            $this->hasLastStreamBeenAborted = $isAborted;

            unset($this->readingCurlHandles[spl_object_id($curlHandle)]);

            $this->closeHandles($curlHandle, $curlMultiHandle);
        }
    }

    private function selectTimeout(float $stallDeadline, ?float $abortDeadline): float
    {
        // Waiting for data ends after a second at the latest, or earlier at
        // the stall deadline or the abort time, whichever comes first.
        $deadline = $abortDeadline === null ? $stallDeadline : min($stallDeadline, $abortDeadline);

        return max(0.0, min(1.0, $deadline - microtime(true)));
    }

    /**
     * Returns the point in time at which the request of the given handle is
     * aborted, or null if abortIn set none for it.
     */
    private function getAbortDeadline(CurlHandle $curlHandle): ?float
    {
        if ($this->abortInCurlHandle !== $curlHandle) {
            return null;
        }

        return $this->abortDeadline;
    }

    private function isAbortTimeOver(?float $abortDeadline): bool
    {
        return $abortDeadline !== null && microtime(true) >= $abortDeadline;
    }

    private function createConnectionStalledException(StallClock $stallClock): ConnectionStalledException
    {
        return new ConnectionStalledException("No data arrived from the server for {$stallClock->getStallTimeout()} seconds.");
    }

    private function closeHandles(CurlHandle $curlHandle, ?CurlMultiHandle $curlMultiHandle): void
    {
        if ($this->abortInCurlHandle === $curlHandle) {
            $this->abortDeadline = null;
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
            $this->stallClock,
        );

        $this->curlHandle = null;
        $this->curlMultiHandle = null;
        $this->header = null;
        $this->write = null;
        $this->stallClock = null;
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
