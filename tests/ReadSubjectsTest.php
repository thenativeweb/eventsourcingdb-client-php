<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\EventCandidate;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class ReadSubjectsTest extends TestCase
{
    use ClientTestTrait;

    public function testReadsNoSubjectsIfTheDatabaseIsEmpty(): void
    {
        $didReadSubjects = false;
        foreach ($this->client->readSubjects('/', new ReadSubjectsOptions(recursive: true)) as $subject) {
            $didReadSubjects = true;
        }

        $this->assertFalse($didReadSubjects, 'Expected no subjects to be read, but some were found.');
    }

    public function testReadsAllSubjects(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/', '/test', '/test/1', '/test/2'], $subjectsRead);
    }

    public function testReadsAllSubjectsFromTheGivenSubject(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/test', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test', '/test/1', '/test/2'], $subjectsRead);
    }

    public function testReadsOnlyTheGivenSubjectIfNotRecursive(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/test', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame(['/test'], $subjectsRead);
    }

    public function testReadsOnlyTheRootSubjectIfNotRecursive(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame(['/'], $subjectsRead);
    }

    public function testReadsASubjectWithoutSubjectsBelowItIfNotRecursive(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/test/1', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame(['/test/1'], $subjectsRead);
    }

    public function testReadsNoSubjectIfTheGivenSubjectIsUnknownAndNotRecursive(): void
    {
        $this->writeEvents();

        $subjectsRead = iterator_to_array($this->client->readSubjects('/unknown', new ReadSubjectsOptions(recursive: false)), false);

        $this->assertSame([], $subjectsRead);
    }

    private function writeEvents(): void
    {
        $this->client->writeEvents([
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test/1',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 23,
                ],
            ),
            new EventCandidate(
                source: 'https://www.eventsourcingdb.io',
                subject: '/test/2',
                type: 'io.eventsourcingdb.test',
                data: [
                    'value' => 42,
                ],
            ),
        ]);
    }
}
