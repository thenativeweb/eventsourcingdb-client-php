# eventsourcingdb

The official PHP client SDK for [EventSourcingDB](https://www.eventsourcingdb.io) – a purpose-built database for event sourcing.

EventSourcingDB enables you to build and operate event-driven applications with native support for writing, reading, and observing events. This client SDK provides convenient access to its capabilities in PHP.

For more information on EventSourcingDB, see its [official documentation](https://docs.eventsourcingdb.io/).

This client SDK includes support for [Testcontainers](https://testcontainers.com/) to spin up EventSourcingDB instances in integration tests. For details, see [Using Testcontainers](#using-testcontainers).

## Getting Started

Install the client SDK:

```shell
composer require thenativeweb/eventsourcingdb
```

Import the `Client` class and create an instance by providing the URL of your EventSourcingDB instance and the API token to use:

```php
require __DIR__ . '/vendor/autoload.php';

use Thenativeweb\Eventsourcingdb\Client;

$client = new Client('http://localhost:3000', 'secret');
```

Then call the `ping` function to check whether the instance is reachable. If it is not, the function will throw an exception:

```php
$client->ping();
```

*Note that `ping` does not require authentication, so the call may succeed even if the API token is invalid.*

If you want to verify the API token, call `verifyApiToken`. If the token is invalid, the function will throw an exception:

```php
$client->verifyApiToken();
```

### Writing Events

Call the `writeEvents` function and hand over an array with one or more events. You do not have to provide all event fields – some are automatically added by the server.

For functionality, `writeEvents` always requires an iterator call via `iterator_count`, `iterator_to_array`, or the `foreach` loop.

Specify `source`, `subject`, `type`, and `data` according to the [CloudEvents](https://docs.eventsourcingdb.io/fundamentals/cloud-events/) format.

The function returns the written events, including the fields added by the server:

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

$writtenEventsArray = iterator_to_array($writtenEvents);
```

#### Using the `isSubjectPristine` precondition

If you only want to write events in case a subject (such as `/books/42`) does not yet have any events, import the `IsSubjectPristine` class, use it to create a precondition, and pass it in an array as the second argument:

```php
use Thenativeweb\Eventsourcingdb\IsSubjectPristine;

$writtenEvents = $client->writeEvents([
  // events
], [
  new IsSubjectPristine('/books/42'),
]);
```

#### Using the `isSubjectOnEventId` precondition

If you only want to write events in case the last event of a subject (such as `/books/42`) has a specific ID (e.g., `0`), import the `IsSubjectOnEventId` class, use it to create a precondition, and pass it in an array as the second argument:

```php
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;

$writtenEvents = $client->writeEvents([
  // events
], [
  new IsSubjectOnEventId('/books/42', '0'),
]);
```

*Note that according to the CloudEvents standard, event IDs must be of type string.*

#### Using the `isEventQlTrue` precondition

If you want to write events depending on an EventQL query, use the `IsEventQlTrue` function to create a precondition:

```php
use Thenativeweb\Eventsourcingdb\IsEventQlTrue;

$writtenEvents = $client->writeEvents([
  // events
], [
  new IsEventQlTrue("FROM e IN events WHERE e.type == 'io.eventsourcingdb.library.book-borrowed' PROJECT INTO COUNT() < 10")
]);
```

*Note that the query must return a single row with a single value, which is interpreted as a boolean.*

### Reading Events

To read all events of a subject, call the `readEvents` function with the subject and an options object. Set the `recursive` option to `false`. This ensures that only events of the given subject are returned, not events of nested subjects.

The function returns an iterator, which you can use in a `foreach` loop:

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

#### Reading From Subjects Recursively

If you want to read not only all the events of a subject, but also the events of all nested subjects, set the `recursive` option to `true`:

```php
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;

$events = $client->readEvents(
  '/books/42',
  new ReadEventsOptions(
    recursive: true,
  ),
);

foreach ($events as $event) {
  // ...
}
```

This also allows you to read *all* events ever written. To do so, provide `/` as the subject and set `recursive` to `true`, since all subjects are nested under the root subject.

#### Reading in Anti-Chronological Order

By default, events are read in chronological order. To read in anti-chronological order, provide the `order` option and set it to `Order::ANTICHRONOLOGICAL`:

```php
use Thenativeweb\Eventsourcingdb\Order;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;

$events = $client->readEvents(
  '/books/42',
  new ReadEventsOptions(
    recursive: false,
    order: Order::ANTICHRONOLOGICAL,
  ),
);

foreach ($events as $event) {
  // ...
}
```

*Note that you can also use `Order::CHRONOLOGICAL` to explicitly enforce the default order.*

#### Specifying Bounds

Sometimes you do not want to read all events, but only a range of events. For that, you can specify the `lowerBound` and `upperBound` options – either one of them or even both at the same time.

Specify the ID and whether to include or exclude it, for both the lower and upper bound:

```php
use Thenativeweb\Eventsourcingdb\Bound;
use Thenativeweb\Eventsourcingdb\BoundType;
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;

$events = $client->readEvents(
  '/books/42',
  new ReadEventsOptions(
    recursive: false,
    lowerBound: new Bound('100', BoundType::INCLUSIVE),
    upperBound: new Bound('200', BoundType::EXCLUSIVE),
  ),
);

foreach ($events as $event) {
  // ...
}
```

#### Starting From the Latest Event of a Given Type

To read starting from the latest event of a given type, provide the `fromLatestEvent` option and specify the subject, the type, and how to proceed if no such event exists.

Possible options are `ReadIfEventIsMissing::READ_NOTHING`, which skips reading entirely, or `ReadIfEventIsMissing::READ_EVERYTHING`, which effectively behaves as if `fromLatestEvent` was not specified:

```php
use Thenativeweb\Eventsourcingdb\ReadEventsOptions;
use Thenativeweb\Eventsourcingdb\ReadFromLatestEvent;
use Thenativeweb\Eventsourcingdb\ReadIfEventIsMissing;

$events = $client->readEvents(
  '/books/42',
  new ReadEventsOptions(
    recursive: false,
    fromLatestEvent: new ReadFromLatestEvent(
      subject: '/books/42',
      type: 'io.eventsourcingdb.library.book-borrowed',
      ifEventIsMissing: ReadIfEventIsMissing::READ_EVERYTHING,
    ),
  ),
);

foreach ($events as $event) {
  // ...
}
```

*Note that `fromLatestEvent` and `lowerBound` can not be provided at the same time.*

### Running EventQL Queries

To run an EventQL query, call the `runEventQlQuery` function and provide the query as a string. The function returns an iterator, which you can use in a `foreach` loop:

```php
$rows = $client->runEventQlQuery(
  'FROM e IN events PROJECT INTO e',
);

foreach ($rows as $row) {
  // ...
}
```

*Note that each row returned by the iterator is an associative array and matches the projection specified in your query.*

### Observing Events

To observe all events of a subject, call the `observeEvents` function with the subject as the first argument and an options object as the second argument. Set the `recursive` option to `false`. This ensures that only events of the given subject are returned, not events of nested subjects.

The function returns an asynchronous iterator, which you can use e.g. inside a `foreach` loop:

```php
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;

$events = $client->observeEvents(
  '/books/42',
  new ObserveEventsOptions(
    recursive: false,
  ),
);

foreach ($events as $event) {
  // ...
}
```

#### Observing From Subjects Recursively

If you want to observe not only all the events of a subject, but also the events of all nested subjects, set the `recursive` option to `true`:

```php
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;

$events = $client->observeEvents(
  '/books/42',
  new ObserveEventsOptions(
    recursive: true,
  ),
);

foreach ($events as $event) {
  // ...
}
```

This also allows you to observe *all* events ever written. To do so, provide `/` as the subject and set `recursive` to `true`, since all subjects are nested under the root subject.

#### Specifying Bounds

Sometimes you do not want to observe all events, but only a range of events. For that, you can specify the `lowerBound` option.

Specify the ID and whether to include or exclude it:

```php
use Thenativeweb\Eventsourcingdb\Bound;
use Thenativeweb\Eventsourcingdb\BoundType;
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;

$events = $client->observeEvents(
  '/books/42',
  new ObserveEventsOptions(
    recursive: false,
    lowerBound: new Bound('100', BoundType::INCLUSIVE),
  ),
);

foreach ($events as $event) {
  // ...
}
```

#### Starting From the Latest Event of a Given Type

To observe starting from the latest event of a given type, provide the `fromLatestEvent` option and specify the subject, the type, and how to proceed if no such event exists.

Possible options are `ObserveIfEventIsMissing::WAIT_FOR_EVENT`, which waits for an event of the given type to happen, or `ObserveIfEventIsMissing::READ_EVERYTHING`, which effectively behaves as if `fromLatestEvent` was not specified:

```php
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;
use Thenativeweb\Eventsourcingdb\ObserveFromLatestEvent;
use Thenativeweb\Eventsourcingdb\ObserveIfEventIsMissing;

$events = $client->observeEvents(
  '/books/42',
  new ObserveEventsOptions(
    recursive: false,
    fromLatestEvent: new ObserveFromLatestEvent(
      subject: '/books/42',
      type: 'io.eventsourcingdb.library.book-borrowed',
      ifEventIsMissing: ObserveIfEventIsMissing::READ_EVERYTHING,
    ),
  ),
);

foreach ($events as $event) {
  // ...
}
```

*Note that `fromLatestEvent` and `lowerBound` can not be provided at the same time.*

#### Aborting Observing

If you need to abort observing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. However, this only works if there is currently an iteration going on:

```php
use Thenativeweb\Eventsourcingdb\ObserveEventsOptions;

$events = $client->observeEvents(
  '/books/42',
  new ObserveEventsOptions(
    recursive: false,
  ),
);

$client->abortIn(0.1);
foreach ($events as $event) {
  // ...
  $client->abortIn(0.1);
}
```

### Registering an Event Schema

To register an event schema, call the `registerEventSchema` function and hand over an event type and the desired schema:

```php
$eventType = 'io.eventsourcingdb.library.book-acquired';
$schema = [
  'type' => 'object',
  'properties' => [
    'title' => ['type' => 'string'],
    'author' => ['type' => 'string'],
    'isbn' => ['type' => 'string'],
  ],
  'required' => [
    'title',
    'author',
    'isbn',
  ],
  'additionalProperties' => false,
];

$client->registerEventSchema($eventType, $schema);
```

### Listing Subjects

To list all subjects, call the `readSubjects` function with `/` as the base subject. The function returns an asynchronous iterator, which you can use e.g. inside a `foreach` loop:

```php
$subjects = $client->readSubjects('/');

foreach($subjects as $subject) {
  // ...
}
```

If you only want to list subjects within a specific branch, provide the desired base subject instead:

```php
$subjects = $client->readSubjects('/books');

foreach($subjects as $subject) {
  // ...
}
```

#### Aborting Listing

If you need to abort listing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. However, this only works if there is currently an iteration going on:

```php
$subjects = $client->readSubjects('/');

$client->abortIn(0.1);
foreach($subjects as $subject) {
  // ...
  $client->abortIn(0.1);
}
```

### Listing Event Types

To list all event types, call the `readEventTypes` function. The function returns an asynchronous iterator, which you can use e.g. inside a `foreach` loop:

```php
$eventTypes = $client->readEventTypes();

foreach($eventTypes as $eventType) {
  // ...
}
```

#### Aborting Listing

If you need to abort listing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. However, this only works if there is currently an iteration going on:

```php
$eventTypes = $client->readEventTypes();

$client->abortIn(0.1);
foreach($eventTypes as $eventType) {
  // ...
  $client->abortIn(0.1);
}
```

### Using Testcontainers

Import the `Container` class, call the `start` function to run a test container, get a client, run your test code, and finally call the `stop` function to stop the test container:

```php
require __DIR__ . '/vendor/autoload.php';

use Thenativeweb\Eventsourcingdb\Container;

$container = new Container();
$container->start();

$client = $container->getClient();

// ...

$container->stop();
```

To check if the test container is running, call the `isRunning` function:

```php
$isRunning = $container->isRunning();
```

#### Configuring the Container Instance

By default, `Container` uses the `latest` tag of the official EventSourcingDB Docker image. To change that, call the `withImageTag` function:

```php
use Thenativeweb\Eventsourcingdb\Container;

$container = new Container()
  ->withImageTag('1.0.0');
```

Similarly, you can configure the port to use and the API token. Call the `withPort` or the `withApiToken` function respectively:

```php
use Thenativeweb\Eventsourcingdb\Container;

$container = new Container()
  ->withPort(4000)
  ->withApiToken('secret');
```

#### Configuring the Client Manually

In case you need to set up the client yourself, use the following functions to get details on the container:

- `getHost()` returns the host name
- `getMappedPort()` returns the port
- `getBaseUrl()` returns the full URL of the container
- `getApiToken()` returns the API token

