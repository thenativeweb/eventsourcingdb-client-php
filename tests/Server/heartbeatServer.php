<?php

declare(strict_types=1);

/*
 * Answers a single request the way EventSourcingDB answers a streaming request:
 * it sends the given lines as NDJSON, one line every interval, and afterwards
 * keeps the connection open without sending anything. It reports "closed" once
 * the client closes the connection, or ends the response and reports "ended"
 * once the hold time is over.
 */

$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}

echo stream_socket_get_name($server, false) . "\n";

$connection = stream_socket_accept($server, 10);
if ($connection === false) {
    exit(1);
}

$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
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

fwrite(
    $connection,
    "HTTP/1.1 200 OK\r\n" .
    "Server: EventSourcingDB/test\r\n" .
    "Content-Type: application/x-ndjson\r\n" .
    "Transfer-Encoding: chunked\r\n" .
    "\r\n",
);

foreach ($options['lines'] as $line) {
    $data = $line . "\n";
    fwrite($connection, dechex(strlen($data)) . "\r\n" . $data . "\r\n");
    usleep((int) ($options['interval'] * 1_000_000));
}

$holdUntil = microtime(true) + $options['holdFor'];
while (($remaining = $holdUntil - microtime(true)) > 0) {
    $read = [$connection];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) === 0) {
        continue;
    }

    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
        echo "closed\n";
        exit(0);
    }
}

fwrite($connection, "0\r\n\r\n");
echo "ended\n";
