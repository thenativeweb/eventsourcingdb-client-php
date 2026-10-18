<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Stream\Queue;

final class QueueTest extends TestCase
{
    public function testQueueIsInitiallyEmpty(): void
    {
        $queue = new Queue();

        $this->assertTrue($queue->isEmpty());
        $this->assertSame('', $queue->read());
    }

    public function testQueueIsInitiallyNotComplete(): void
    {
        $queue = new Queue();

        $this->assertFalse($queue->isComplete());
    }

    public function testCompleteMarksTheQueueAsComplete(): void
    {
        $queue = new Queue();
        $queue->write('foo');
        $queue->complete();

        $this->assertTrue($queue->isComplete());
        $this->assertSame('foo', $queue->read());
    }

    public function testWriteAddsDataToQueue(): void
    {
        $queue = new Queue();
        $queue->write('foo');
        $queue->write('bar');

        $this->assertFalse($queue->isEmpty());
        $this->assertSame('foo', $queue->read());
        $this->assertSame('bar', $queue->read());
        $this->assertSame('', $queue->read());
        $this->assertTrue($queue->isEmpty());
    }

    public function testWriteIgnoresEmptyStrings(): void
    {
        $queue = new Queue();
        $queue->write('');
        $queue->write('test');

        $this->assertFalse($queue->isEmpty());
        $this->assertSame('test', $queue->read());
        $this->assertSame('', $queue->read());
    }

    public function testMaxSizeLimitsQueue(): void
    {
        $queue = new Queue([], 2);
        $queue->write('one');
        $queue->write('two');
        $queue->write('three');

        $this->assertSame('two', $queue->read());
        $this->assertSame('three', $queue->read());
        $this->assertSame('', $queue->read());
    }

    public function testGetIteratorReturnsAllItemsInOrder(): void
    {
        $queue = new Queue();
        $queue->write('first');
        $queue->write('second');

        $items = iterator_to_array($queue);
        $this->assertSame(['first', 'second'], $items);
    }

    public function testWriteKeepsDataThatIsOnlyWhitespace(): void
    {
        // A response may arrive in chunks that consist of whitespace only,
        // e.g. in the middle of a long string of spaces, and dropping them
        // would change the response.
        $queue = new Queue();
        $queue->write("\n");
        $queue->write("\t");
        $queue->write('   ');

        $this->assertSame("\n", $queue->read());
        $this->assertSame("\t", $queue->read());
        $this->assertSame('   ', $queue->read());
        $this->assertTrue($queue->isEmpty());
    }
}
