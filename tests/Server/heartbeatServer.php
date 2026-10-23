<?php

declare(strict_types=1);

/*
 * Answers one request per given response, one after another, each the way
 * EventSourcingDB answers a streaming request: after the given delay, it sends
 * the headers, with the given status, the given Server and Content-Type
 * headers, any further given headers, and the rest of them the given header
 * delay after the status line, and then the given lines as NDJSON, one line
 * every interval, and afterwards keeps the connection open without sending
 * anything. It reports "closed" once the client closes the connection, at any
 * of these steps, or ends the response and reports "ended" once the hold time
 * is over. A response that drops the connection closes it as soon as the
 * request has arrived, without answering, and reports "dropped".
 *
 * If the connection ends before a request arrives, it reports "no request"
 * instead, and if it ends after only a part of a request arrived, e.g. the
 * start of a TLS handshake that it does not answer, it reports "closed".
 *
 * If routes are given rather than responses, it answers every request by its
 * path instead, with the responses given for that path, one after another,
 * and with the last of them again for every further request, and a path
 * without responses with 404, as EventSourcingDB answers a route that it does
 * not know, until the test ends it.
 *
 * If a request log is given, it appends every request to it, as a line of
 * JSON with its method, its path, and its body, before answering it.
 *
 * It reports its address once it listens, after the given start delay, which
 * stands in for a machine so busy that starting the server takes long. If it
 * is told to fail before reporting, it throws instead, which stands in for a
 * server that fails to start.
 *
 * If a certificate is given, it serves https with it and the given private
 * key. A client that does not trust the certificate either breaks off the TLS
 * handshake or closes the connection right after it, so the server reports
 * "no request" in both cases.
 */

/**
 * @param resource $connection
 * @param Closure(string): array $chooseResponse returns the response to the request with the given path
 */
function answer($connection, Closure $chooseResponse, ?string $requestLog): string
{
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        // A client that does not trust the certificate may close the
        // connection only after the handshake, which makes reading from it
        // warn. PHP would print the warning to the output that the outcome is
        // reported on, so it is suppressed, and the outcome is reported below.
        $chunk = @fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            return $request === '' ? 'no request' : 'closed';
        }

        $request .= $chunk;
    }

    [$head, $body] = explode("\r\n\r\n", $request, 2);
    $contentLength = preg_match('/^Content-Length:\s*(\d+)/im', $head, $matches) ? (int) $matches[1] : 0;
    while (strlen($body) < $contentLength) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            exit(1);
        }

        $body .= $chunk;
    }

    [$method, $path] = explode(' ', strtok($head, "\r\n") ?: '', 3) + ['', ''];
    if ($requestLog !== null) {
        file_put_contents($requestLog, json_encode([
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    $response = $chooseResponse($path);

    if ($response['dropConnection'] ?? false) {
        return 'dropped';
    }

    if (!waitFor($connection, $response['delay'])) {
        return 'closed';
    }

    $status = $response['status'] ?? 200;
    @fwrite($connection, "HTTP/1.1 {$status} Status\r\n");
    if (!waitFor($connection, $response['headerDelay'])) {
        return 'closed';
    }

    $headers = '';
    foreach ($response['headers'] ?? [] as $header) {
        $headers .= "{$header}\r\n";
    }

    @fwrite(
        $connection,
        "Server: {$response['server']}\r\n" .
        "Content-Type: {$response['contentType']}\r\n" .
        $headers .
        "Transfer-Encoding: chunked\r\n" .
        "\r\n",
    );

    foreach ($response['lines'] as $line) {
        $data = $line . "\n";
        @fwrite($connection, dechex(strlen($data)) . "\r\n" . $data . "\r\n");
        if (!waitFor($connection, $response['interval'])) {
            return 'closed';
        }
    }

    if (!waitFor($connection, $response['holdFor'])) {
        return 'closed';
    }

    @fwrite($connection, "0\r\n\r\n");

    return 'ended';
}

/**
 * Waits for the given number of seconds, and tells whether the connection is
 * still open afterwards, so that nothing is sent to a client that has gone.
 * Reading from or writing to a connection the client has closed may warn, and
 * PHP would print the warning to the output that the outcome is reported on,
 * so it is suppressed.
 *
 * @param resource $connection
 */
function waitFor($connection, float $seconds): bool
{
    $waitUntil = microtime(true) + $seconds;
    while (($remaining = $waitUntil - microtime(true)) > 0) {
        $read = [$connection];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) === 0) {
            continue;
        }

        $chunk = @fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            return false;
        }
    }

    return true;
}

/**
 * Accepts the next connection, answers the request on it with the response
 * that the given function chooses, and reports the outcome.
 *
 * @param resource $server
 * @param Closure(string): array $chooseResponse
 */
function serveConnection($server, ?array $tls, Closure $chooseResponse, ?string $requestLog): void
{
    // The test waits a minute at most for a report, so the server waits as
    // long for the next request, which a busy machine may send late. The
    // test ends the server in any case once it is done.
    $connection = stream_socket_accept($server, 60);
    if ($connection === false) {
        exit(1);
    }

    // A handshake the client breaks off is an expected outcome, so its warning
    // is suppressed, and the outcome is reported instead.
    if ($tls !== null && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        echo "no request\n";

        fclose($connection);

        return;
    }

    echo answer($connection, $chooseResponse, $requestLog) . "\n";

    fclose($connection);
}

$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$tls = $options['tls'] ?? null;
$requestLog = $options['requestLog'] ?? null;

$server = stream_socket_server(
    'tcp://127.0.0.1:0',
    context: stream_context_create([
        'ssl' => $tls === null ? [] : [
            'local_cert' => $tls['certificate'],
            'local_pk' => $tls['privateKey'],
        ],
    ]),
);
if ($server === false) {
    exit(1);
}

usleep((int) (($options['startDelay'] ?? 0.0) * 1_000_000));

if ($options['failBeforeReporting'] ?? false) {
    throw new RuntimeException('The test server was told to fail before reporting its address.');
}

echo stream_socket_get_name($server, false) . "\n";

$routes = $options['routes'] ?? null;
if ($routes === null) {
    foreach ($options['responses'] as $response) {
        serveConnection($server, $tls, static fn (): array => $response, $requestLog);
    }

    exit(0);
}

$notFound = [
    'lines' => ['404 page not found'],
    'interval' => 0.0,
    'holdFor' => 0.0,
    'delay' => 0.0,
    'server' => 'EventSourcingDB/test',
    'contentType' => 'text/plain; charset=utf-8',
    'headerDelay' => 0.0,
    'status' => 404,
];
$chooseResponse = static function (string $path) use (&$routes, $notFound): array {
    if (!isset($routes[$path])) {
        return $notFound;
    }

    return count($routes[$path]) > 1 ? array_shift($routes[$path]) : $routes[$path][0];
};

while (true) {
    serveConnection($server, $tls, $chooseResponse, $requestLog);
}
