<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\StallClock;

final class StallClockTest extends TestCase
{
    public function testHasNotStalledWithinTheStallTimeout(): void
    {
        $stallClock = new StallClock(30.0);

        $this->assertFalse($stallClock->hasStalled());
        $this->assertEqualsWithDelta(microtime(true) + 30.0, $stallClock->getDeadline(), 0.1);
    }

    public function testHasStalledOnceNothingArrivedForTheStallTimeout(): void
    {
        $stallClock = new StallClock(0.05);

        usleep(60_000);

        $this->assertTrue($stallClock->hasStalled());
    }

    public function testStartsTheStallTimeoutAgainWhenReset(): void
    {
        $stallClock = new StallClock(0.05);

        usleep(60_000);
        $stallClock->reset();

        $this->assertFalse($stallClock->hasStalled());
    }
}
