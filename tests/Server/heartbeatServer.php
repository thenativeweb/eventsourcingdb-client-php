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
 * If a request log is given, it appends every request to it, as a line of
 * JSON with its method, its path, and its body, before answering it.
 *
 * If a certificate is given, it serves https with it and the given private
 * key. A client that does not trust the certificate either breaks off the TLS
 * handshake or closes the connection right after it, so the server reports
 * "no request" in both cases.
 */

/**
 * @param resource $connection
 */
function answer($connection, array $response, ?string $requestLog): string
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

    if ($requestLog !== null) {
        [$method, $path] = explode(' ', strtok($head, "\r\n") ?: '', 3) + ['', ''];
        file_put_contents($requestLog, json_encode([
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

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

echo stream_socket_get_name($server, false) . "\n";

foreach ($options['responses'] as $response) {
    $connection = stream_socket_accept($server, 10);
    if ($connection === false) {
        exit(1);
    }

    // A handshake the client breaks off is an expected outcome, so its warning
    // is suppressed, and the outcome is reported instead.
    if ($tls !== null && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        echo "no request\n";

        fclose($connection);

        continue;
    }

    echo answer($connection, $response, $requestLog) . "\n";

    fclose($connection);
}
