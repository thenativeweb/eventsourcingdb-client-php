<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

/**
 * Tells whether a request has stalled, which it has once nothing at all has
 * arrived from the server for the stall timeout. Everything that arrives,
 * be it a part of the headers, a part of the body, or a heartbeat, resets it,
 * so a request that keeps receiving something never stalls, however long it
 * takes.
 *
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\StallClockTest
 */
final class StallClock
{
    private float $lastArrivalTime;

    public function __construct(
        private readonly float $stallTimeout,
    ) {
        $this->reset();
    }

    public function getStallTimeout(): float
    {
        return $this->stallTimeout;
    }

    public function reset(): void
    {
        $this->lastArrivalTime = microtime(true);
    }

    public function getDeadline(): float
    {
        return $this->lastArrivalTime + $this->stallTimeout;
    }

    public function hasStalled(): bool
    {
        return microtime(true) >= $this->getDeadline();
    }
}
