<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use Iterator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFailsIfTheServerFailsBeforeReportingItsAddressAlsoIfPhpDisplaysErrors(): void
    {
        // A php.ini may tell PHP to display errors, which it does on the
        // output that the server reports on.
        $iniDirectory = sys_get_temp_dir() . '/eventsourcingdb-ini-' . bin2hex(random_bytes(8));
        mkdir($iniDirectory);
        file_put_contents("{$iniDirectory}/display-errors.ini", "display_errors = On\n");

        // An empty entry keeps the directory that PHP scans by default.
        $scanDirectory = getenv('PHP_INI_SCAN_DIR');
        putenv('PHP_INI_SCAN_DIR=' . PATH_SEPARATOR . $iniDirectory);

        try {
            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage('The test server exited without reporting its address');

            $this->startServerWithResponses([], failBeforeReporting: true);
        } finally {
            putenv($scanDirectory === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . $scanDirectory);
            unlink("{$iniDirectory}/display-errors.ini");
            rmdir($iniDirectory);
        }
    }

    public static function malformedReports(): Iterator
    {
        yield 'an empty line' => ["\n", "''"];
        yield 'a line that is not an outcome' => ["Warning: something went wrong\n", "'Warning: something went wrong'"];
    }

    #[DataProvider('malformedReports')]
    public function testFailsIfTheServerReportsSomethingOtherThanTheOutcomeOfAConnection(string $output, string $reported): void
    {
        // A process that writes the given output stands in for the server.
        $server = proc_open([PHP_BINARY, '-r', 'echo ' . var_export($output, true) . ';'], [
            1 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($server);

        $this->server = $server;
        $this->serverOutput = $pipes[1];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The test server reported {$reported} rather than the outcome of a connection.");

        $this->readServerReport();
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
