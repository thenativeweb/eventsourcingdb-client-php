<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\Order;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\Stream\FileUpload;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class BackupTest extends TestCase
{
    use ClientTestTrait;

    public function testBackup(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $this->client->backup(__DIR__ . '/data.json');
        $this->expectNotToPerformAssertions();
    }

    public function testRestore(): void
    {
        $this->client->restore(new FileUpload(new SplFileObject(__DIR__ . '/data.json')));

        $eventsRead = [];
        $readEventsOptions = new ReadEventsOptions(
            recursive: false,
            // order: Order::CHRONOLOGICAL,
        );

        foreach ($this->client->readEvents('/test', $readEventsOptions) as $event) {
            $eventsRead[] = $event;
        }

        $this->assertCount(2, $eventsRead);
        // $this->assertInstanceOf(CloudEvent::class, $eventsRead[0]);
        // $this->assertSame('0', $eventsRead[0]->id);
        // $this->assertSame(23, $eventsRead[0]->data['value']);
        // $this->assertInstanceOf(CloudEvent::class, $eventsRead[1]);
        // $this->assertSame('1', $eventsRead[1]->id);
        // $this->assertSame(42, $eventsRead[1]->data['value']);
    }
}
