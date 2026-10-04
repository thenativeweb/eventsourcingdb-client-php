<?php

declare(strict_types=1);

/*
 * Answers one request per given response, one after another, each the way
 * EventSourcingDB answers a streaming request: after the given delay, it sends
 * the headers, with the given Server and Content-Type headers and the rest of
 * them the given header delay after the status line, and then the given lines
 * as NDJSON, one line every interval, and afterwards keeps the connection open
 * without sending anything. It reports "closed" once the client closes the
 * connection, or ends the response and reports "ended" once the hold time is
 * over.
 *
 * If the connection ends before a request arrives, it reports "no request"
 * instead.
 *
 * If a certificate is given, it serves https with it and the given private
 * key. A client that does not trust the certificate either breaks off the TLS
 * handshake or closes the connection right after it, so the server reports
 * "no request" in both cases.
 */

/**
 * @param resource $connection
 */
function answer($connection, array $response): string
{
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            if ($request === '') {
                return 'no request';
            }

            exit(1);
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

    usleep((int) ($response['delay'] * 1_000_000));

    fwrite($connection, "HTTP/1.1 200 OK\r\n");
    usleep((int) ($response['headerDelay'] * 1_000_000));

    fwrite(
        $connection,
        "Server: {$response['server']}\r\n" .
        "Content-Type: {$response['contentType']}\r\n" .
        "Transfer-Encoding: chunked\r\n" .
        "\r\n",
    );

    foreach ($response['lines'] as $line) {
        $data = $line . "\n";
        fwrite($connection, dechex(strlen($data)) . "\r\n" . $data . "\r\n");
        usleep((int) ($response['interval'] * 1_000_000));
    }

    $holdUntil = microtime(true) + $response['holdFor'];
    while (($remaining = $holdUntil - microtime(true)) > 0) {
        $read = [$connection];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) === 0) {
            continue;
        }

        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            return 'closed';
        }
    }

    fwrite($connection, "0\r\n\r\n");

    return 'ended';
}

$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$tls = $options['tls'] ?? null;

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

    echo answer($connection, $response) . "\n";

    fclose($connection);
}
