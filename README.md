# eventsourcingdb

The official PHP client SDK for [EventSourcingDB](https://www.eventfoundation.io/products/eventsourcingdb) – a purpose-built database for event sourcing.

The complete documentation of this client SDK, including preconditions, EventQL, observing events, handling errors, Testcontainers, and upgrading, is available at **[https://www.eventfoundation.io/docs/eventsourcingdb/sdks/php](https://www.eventfoundation.io/docs/eventsourcingdb/sdks/php)**.

## Getting Started

Install the client SDK:

```shell
composer require thenativeweb/eventsourcingdb
```

Create a client with the URL of your EventSourcingDB instance and the API token to use, and check whether the instance is reachable:

```php
require __DIR__ . '/vendor/autoload.php';

use Thenativeweb\Eventsourcingdb\Client;

$client = new Client('http://localhost:3000', 'secret');

$client->ping();
```

Write an event:

```php
use Thenativeweb\Eventsourcingdb\EventCandidate;

$writtenEvents = $client->writeEvents([
  new EventCandidate(
    'https://library.eventsourcingdb.io',
    '/books/42',
    'io.eventsourcingdb.library.book-acquired',
    [
      'title' => '2001 – A Space Odyssey',
      'author' => 'Arthur C. Clarke',
      'isbn' => '978-0756906788',
    ],
  ),
]);
```

Read the events of a subject:

```php
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;

$events = $client->readEvents(
  '/books/42',
  new ReadEventsOptions(
    recursive: false,
  ),
);

foreach ($events as $event) {
  // ...
}
```

For everything else, see the **[complete documentation](https://www.eventfoundation.io/docs/eventsourcingdb/sdks/php)**.
