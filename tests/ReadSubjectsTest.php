<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\Tests\ClientTestTrait;

final class ReadSubjectsTest extends TestCase
{
    use ClientTestTrait;

    public function testReadsNoSubjectsIfTheDatabaseIsEmpty(): void
    {
        $didReadSubjects = false;
        foreach ($this->client->readSubjects('/') as $event) {
            $didReadSubjects = true;
        }

        $this->assertFalse($didReadSubjects, 'Expected no rows to be read, but some were found.');
    }

    public function testReadsAllSubjects(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test/1',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test/2',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $subjectsRead = [];
        foreach ($this->client->readSubjects('/') as $subject) {
            $subjectsRead[] = $subject;
        }

        $this->assertCount(4, $subjectsRead);
        $this->assertSame('/', $subjectsRead[0]);
        $this->assertSame('/test', $subjectsRead[1]);
        $this->assertSame('/test/1', $subjectsRead[2]);
        $this->assertSame('/test/2', $subjectsRead[3]);
    }

    public function testReadsAllSubjectsFromTheBaseSubject(): void
    {
        $firstEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test/1',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 23,
            ],
        );

        $secondEvent = new EventCandidate(
            source: 'https://www.eventsourcingdb.io',
            subject: '/test/2',
            type: 'io.eventsourcingdb.test',
            data: [
                'value' => 42,
            ],
        );

        $this->client->writeEvents([
            $firstEvent,
            $secondEvent,
        ]);

        $subjectsRead = [];
        foreach ($this->client->readSubjects('/test') as $subject) {
            $subjectsRead[] = $subject;
        }

        $this->assertCount(3, $subjectsRead);
        $this->assertSame('/test', $subjectsRead[0]);
        $this->assertSame('/test/1', $subjectsRead[1]);
        $this->assertSame('/test/2', $subjectsRead[2]);
    }
}
