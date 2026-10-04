<?php

declare(strict_types=1);

/*
 * Pings the database at the given URL and reports "pinged", or the message of
 * the error the ping fails with. It runs in a process of its own, so that the
 * openssl.cafile setting can set the authorities whose certificates it trusts,
 * since PHP does not let a running script change that setting.
 */

use Thenativeweb\Eventsourcingdb\Client;

require __DIR__ . '/../../vendor/autoload.php';

$client = new Client($argv[1], 'secret');

try {
    $client->ping();

    echo "pinged\n";
} catch (Throwable $throwable) {
    echo $throwable->getMessage() . "\n";
}
