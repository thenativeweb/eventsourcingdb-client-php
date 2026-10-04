<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Trait;

trait ServerTestTrait
{
    /**
     * @var resource|null
     */
    private $server;

    /**
     * @var resource|null
     */
    private $serverOutput;

    protected function tearDown(): void
    {
        if (is_resource($this->serverOutput)) {
            fclose($this->serverOutput);
        }

        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        parent::tearDown();
    }

    private function startServer(array $lines, float $interval, float $holdFor, float $delay = 0.0): string
    {
        return $this->startServerWithResponses([
            $this->response($lines, $interval, $holdFor, $delay),
        ]);
    }

    private function startServerWithResponses(array $responses): string
    {
        $server = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/../Server/heartbeatServer.php',
                json_encode([
                    'responses' => $responses,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                1 => ['pipe', 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($server);

        $this->server = $server;
        $this->serverOutput = $pipes[1];

        return $this->readServerReport();
    }

    private function response(array $lines, float $interval, float $holdFor, float $delay = 0.0): array
    {
        return [
            'lines' => $lines,
            'interval' => $interval,
            'holdFor' => $holdFor,
            'delay' => $delay,
        ];
    }

    private function readServerReport(): string
    {
        $read = [$this->serverOutput];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 2) !== 1) {
            return '';
        }

        return trim((string) fgets($this->serverOutput));
    }
}
