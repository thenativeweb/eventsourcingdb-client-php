<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Trait;

/**
 * Starts a server that answers requests the way EventSourcingDB does, with
 * given responses, see heartbeatServer.php.
 */
trait ServerTestTrait
{
    /**
     * The ping of the v2 API, as EventSourcingDB answers it.
     */
    private static string $pingOfV2 = '{"type":"event","data":{"specversion":"1.0","id":"0","source":"https://www.eventsourcingdb.io","type":"io.eventsourcingdb.api.ping-received","subject":"/api/v2/system/ping","time":"2026-01-01T00:00:00Z","datacontenttype":"application/json","data":{"message":"My God, it\'s full of stars."}}}';

    /**
     * @var resource|null
     */
    private $server;

    /**
     * @var resource|null
     */
    private $serverOutput;

    private ?string $requestLog = null;

    /**
     * The file that the errors of the server go to, apart from its reports,
     * so that a failure can name them.
     */
    private ?string $serverErrors = null;

    /**
     * The seconds to wait at most for a report of the server, which is far
     * longer than any report takes, even on a machine that is busy, so that a
     * slow start of the server or of a request does not fail a test, while a
     * report that never comes still does. It is longer than the minute that
     * the server waits for the next connection, so that a server that gives
     * up on it exits first, and the test fails with a message that says so.
     */
    private static float $reportTimeout = 70.0;

    /**
     * The outcomes of a connection that the server reports.
     */
    private static string $outcomePattern = '/^(closed|ended|dropped|no request)$/';

    /**
     * The number of reports to skip, those of the requests that find out which
     * API the server offers, so that a test reads the reports of the requests
     * it makes itself.
     */
    private int $reportsToSkip = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->serverOutput)) {
            fclose($this->serverOutput);
        }

        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->requestLog !== null && file_exists($this->requestLog)) {
            unlink($this->requestLog);
        }

        if ($this->serverErrors !== null && file_exists($this->serverErrors)) {
            unlink($this->serverErrors);
        }

        parent::tearDown();
    }

    /**
     * Starts a server that tells a client that it offers the given API, and
     * then answers its requests with the given responses.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private function startDatabase(string $apiVersion, array $responses): string
    {
        $address = $this->startServerWithResponses([
            $this->probeResponse($apiVersion),
            ...$responses,
        ]);

        $this->reportsToSkip = 1;

        return $address;
    }

    /**
     * The answer to the request with which a client finds out which API the
     * server offers.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private function probeResponse(string $apiVersion): array
    {
        if ($apiVersion === 'v2') {
            return $this->response([self::$pingOfV2], interval: 0.0, holdFor: 0.0, contentType: 'application/json');
        }

        return $this->response(['404 page not found'], interval: 0.0, holdFor: 0.0, contentType: 'text/plain; charset=utf-8', status: 404);
    }

    /**
     * The answer to a ping, in the shape of the given API.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private function pingResponse(string $apiVersion): array
    {
        if ($apiVersion === 'v2') {
            return $this->response([self::$pingOfV2], interval: 0.0, holdFor: 0.0, contentType: 'application/json');
        }

        return $this->response(['{"type":"io.eventsourcingdb.api.ping-received"}'], interval: 0.0, holdFor: 0.0, contentType: 'application/json');
    }

    /**
     * An item of a stream, in the shape of the given API.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private static function item(string $apiVersion, string $type, mixed $data = []): string
    {
        return json_encode([
            'type' => $type,
            $apiVersion === 'v2' ? 'data' : 'payload' => $data === [] ? (object) [] : $data,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * An event, as the given API sends it.
     *
     * @param 'v1'|'v2' $apiVersion
     */
    private static function eventItem(string $apiVersion, string $id = '0'): string
    {
        $event = [
            'specversion' => '1.0',
            'id' => $id,
            'source' => 'https://www.eventsourcingdb.io',
            'subject' => '/test',
            'type' => 'io.eventsourcingdb.test',
            'time' => '2026-01-01T00:00:00Z',
            'predecessorhash' => 'predecessorhash',
            'datacontenttype' => 'application/json',
            'data' => [
                'value' => 23,
            ],
        ];

        if ($apiVersion === 'v2') {
            $event['traceparent'] = null;
            $event['tracestate'] = null;
        }

        $event['hash'] = 'hash';
        $event['signature'] = null;

        return self::item($apiVersion, 'event', $event);
    }

    private function startServer(array $lines, float $interval, float $holdFor, float $delay = 0.0): string
    {
        return $this->startServerWithResponses([
            $this->response($lines, $interval, $holdFor, $delay),
        ]);
    }

    /**
     * @param array{certificate: string, privateKey: string}|null $tls the files of the certificate and its private key to serve https with
     */
    private function startServerWithResponses(array $responses, ?array $tls = null, float $startDelay = 0.0, bool $failBeforeReporting = false): string
    {
        $this->requestLog = tempnam(sys_get_temp_dir(), 'eventsourcingdb-requests-') ?: null;
        $this->reportsToSkip = 0;

        $serverErrors = tempnam(sys_get_temp_dir(), 'eventsourcingdb-server-errors-');
        $this->assertIsString($serverErrors);
        $this->serverErrors = $serverErrors;

        // PHP displays errors on the output if a php.ini tells it to, which
        // would mix them into the reports, so they go to the error output,
        // which is kept apart.
        $server = proc_open(
            [
                PHP_BINARY,
                '-d',
                'display_errors=stderr',
                __DIR__ . '/../Server/heartbeatServer.php',
                json_encode([
                    'responses' => $responses,
                    'tls' => $tls,
                    'requestLog' => $this->requestLog,
                    'startDelay' => $startDelay,
                    'failBeforeReporting' => $failBeforeReporting,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['file', $serverErrors, 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($server);

        $this->server = $server;
        $this->serverOutput = $pipes[1];

        return $this->readReport('its address', '/^\d{1,3}(\.\d{1,3}){3}:\d+$/');
    }

    /**
     * @param list<string> $headers further headers of the response, such as 'Retry-After: 7'
     */
    private function response(
        array $lines,
        float $interval,
        float $holdFor,
        float $delay = 0.0,
        string $server = 'EventSourcingDB/test',
        string $contentType = 'application/x-ndjson',
        float $headerDelay = 0.0,
        int $status = 200,
        array $headers = [],
        bool $dropConnection = false,
    ): array {
        return [
            'lines' => $lines,
            'interval' => $interval,
            'holdFor' => $holdFor,
            'delay' => $delay,
            'server' => $server,
            'contentType' => $contentType,
            'headerDelay' => $headerDelay,
            'status' => $status,
            'headers' => $headers,
            'dropConnection' => $dropConnection,
        ];
    }

    /**
     * An answer with the given status and plain text, as EventSourcingDB
     * answers a request that it cannot accept.
     *
     * @param list<string> $headers
     */
    private function textResponse(int $status, string $text, array $headers = [], string $server = 'EventSourcingDB/test'): array
    {
        return $this->response(
            [$text],
            interval: 0.0,
            holdFor: 0.0,
            server: $server,
            contentType: 'text/plain; charset=utf-8',
            status: $status,
            headers: $headers,
        );
    }

    private function readServerReport(): string
    {
        while ($this->reportsToSkip > 0) {
            --$this->reportsToSkip;
            $this->readReport('the outcome of a connection', self::$outcomePattern);
        }

        return $this->readReport('the outcome of a connection', self::$outcomePattern);
    }

    /**
     * Returns the requests that the server received, in order, each with its
     * method, its path, and its body.
     *
     * @return list<array{method: string, path: string, body: string}>
     */
    private function readRequests(): array
    {
        if ($this->requestLog === null) {
            return [];
        }

        $lines = file($this->requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /**
     * Reads the next line that the server reports, and waits for it until the
     * server reports it or exits, for the report timeout at most. If the line
     * does not come, or does not match the given pattern, the test fails,
     * rather than go on with it, such as with an empty address, which would
     * fail the test later in a misleading way.
     *
     * @param string $what what the server is to report, for the message of a failure
     * @param string $pattern the pattern that the report has to match
     */
    private function readReport(string $what, string $pattern): string
    {
        $deadline = microtime(true) + self::$reportTimeout;

        while (($remaining = $deadline - microtime(true)) > 0) {
            $read = [$this->serverOutput];
            $write = null;
            $except = null;

            $seconds = (int) $remaining;
            $ready = stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
            if ($ready === false) {
                $this->fail("Failed to wait for the test server to report {$what}.");
            }

            if ($ready === 0) {
                continue;
            }

            $line = fgets($this->serverOutput);
            if ($line === false) {
                $this->fail("The test server exited without reporting {$what}." . $this->describeServerErrors());
            }

            $report = trim($line);
            if (preg_match($pattern, $report) !== 1) {
                $this->fail(sprintf('The test server reported %s rather than %s.', var_export($report, true), $what) . $this->describeServerErrors());
            }

            return $report;
        }

        $this->fail(sprintf('The test server did not report %s within %d seconds.', $what, self::$reportTimeout) . $this->describeServerErrors());
    }

    /**
     * Returns the errors of the server, if there are any, to append to the
     * message of a failure.
     */
    private function describeServerErrors(): string
    {
        $errors = $this->serverErrors === null ? '' : trim((string) @file_get_contents($this->serverErrors));

        return $errors === '' ? '' : " Its errors: {$errors}";
    }
}
