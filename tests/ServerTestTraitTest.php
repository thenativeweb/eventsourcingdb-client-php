<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class ServerTestTraitTest extends TestCase
{
    use ServerTestTrait;

    public function testWaitsForTheAddressOfAServerThatTakesLongToStart(): void
    {
        // Starting the server takes longer than the two seconds after which
        // the tests used to give up and go on with an empty address.
        $address = $this->startServerWithResponses([
            $this->probeResponse('v1'),
            $this->response([self::item('v1', 'subject', [
                'subject' => '/test',
            ])], interval: 0.0, holdFor: 0.0),
        ], startDelay: 2.5);

        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);

        $client = new Client("http://{$address}", 'secret');
        $subjects = iterator_to_array($client->readSubjects('/', new ReadSubjectsOptions(recursive: true)), false);

        $this->assertSame(['/test'], $subjects);
    }

    public function testFailsIfTheServerExitsWithoutReporting(): void
    {
        // A server without responses exits right after reporting its address.
        $this->startServerWithResponses([]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The test server exited without reporting the outcome of a connection.');

        $this->readServerReport();
    }
}
