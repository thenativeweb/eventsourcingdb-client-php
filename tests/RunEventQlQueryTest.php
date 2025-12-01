<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class RunEventQlQueryTest extends TestCase
{
    use ClientTestTrait;

    public function testNoRowsIfTheQueryDoesNotReturnAnyRows(): void
    {
        $didReadRows = false;
        foreach ($this->client->runEventQlQuery('FROM e IN events PROJECT INTO e') as $event) {
            $didReadRows = true;
        }

        $this->assertFalse($didReadRows, 'Expected no rows to be read, but some were found.');
    }

    public function testReadsAllRowsTheQueryReturn(): void
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

        $rowsRead = [];
        foreach ($this->client->runEventQlQuery('FROM e IN events PROJECT INTO e') as $row) {
            $rowsRead[] = $row;
        }

        $this->assertCount(2, $rowsRead);
        $this->assertIsArray($rowsRead[0]);
        $this->assertSame('0', $rowsRead[0]['id']);
        $this->assertSame(23, $rowsRead[0]['data']['value']);
        $this->assertIsArray($rowsRead[1]);
        $this->assertSame('1', $rowsRead[1]['id']);
        $this->assertSame(42, $rowsRead[1]['data']['value']);
    }
}
