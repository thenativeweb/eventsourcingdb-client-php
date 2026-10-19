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

### Talking to Servers With and Without the v2 API

Creating a client does not send a request. The client SDK works with servers that offer the v2 API of EventSourcingDB, which it offers since version 1.3, and with servers that only offer the v1 API. On its first request, the client asks the server for the ping of the v2 API, `GET /api/v2/system/ping`, and remembers the answer: if the server answers it, the client uses the v2 API, and if the server does not know the route, the client uses the v1 API. If asking fails, e.g. because the server is not reachable, the first request fails with that error, and the next request asks again.

*Note that the v2 API reads with the HTTP method `QUERY` ([RFC 10008](https://www.rfc-editor.org/rfc/rfc10008)), which a proxy in front of the database has to let through. There is no fallback to `POST`.*

### Configuring the Stall Timeout

A request fails once nothing at all, neither data nor a heartbeat, has arrived from the server for 30 seconds, counted from sending the request, including connecting and waiting for the response headers. This keeps a stalled connection from blocking forever. Since anything that arrives resets this time, a long request that keeps receiving data, such as reading many events or observing events, is never cut off.

To change the stall timeout, pass the `stallTimeout` option in seconds when creating the client:

```php
$client = new Client('http://localhost:3000', 'secret', stallTimeout: 10);
```

If a request stalls, the client closes the connection and throws a `ConnectionStalledException`, which extends `RuntimeException`. This holds for every request, including streams such as `observeEvents` and `runEventQlQuery`, which receive a heartbeat every second while there is nothing else to send. If a write stalls once the connection to the server is established, the client throws an `OutcomeUnknownException` instead, whose previous exception is the `ConnectionStalledException`, since the server may have stored the write nonetheless. A write that stalls while connecting was never sent, so the client throws the `ConnectionStalledException` itself, see [Knowing Whether a Write Was Stored](#knowing-whether-a-write-was-stored).

### Writing Events

Call the `writeEvents` function and hand over an array with one or more events. You do not have to provide all event fields – some are automatically added by the server.

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

If a precondition does not hold, the function throws a `DbApiException` whose `errorCode` is `ErrorCode::PRECONDITION_VIOLATED`, and writes no event:

```php
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;

try {
  $writtenEvents = $client->writeEvents([
    // events
  ], [
    new IsSubjectPristine('/books/42'),
  ]);
} catch (DbApiException $exception) {
  if ($exception->errorCode === ErrorCode::PRECONDITION_VIOLATED) {
    // ...
  }
}
```

The same holds for the other preconditions.

#### Using the `isSubjectPopulated` precondition

If you only want to write events in case a subject (such as `/books/42`) already has at least one event, import the `IsSubjectPopulated` class, use it to create a precondition, and pass it in an array as the second argument:

```php
use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;

$writtenEvents = $client->writeEvents([
  // events
], [
  new IsSubjectPopulated('/books/42'),
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

#### Using the `isEventQlQueryTrue` precondition

If you want to write events depending on an EventQL query, use the `IsEventQlQueryTrue` function to create a precondition:

```php
use Thenativeweb\Eventsourcingdb\IsEventQlQueryTrue;

$writtenEvents = $client->writeEvents([
  // events
], [
  new IsEventQlQueryTrue("FROM e IN events WHERE e.type == 'io.eventsourcingdb.library.book-borrowed' PROJECT INTO COUNT() < 10")
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

*Note that if you leave the `foreach` loop early, e.g. with `break`, the connection is closed once the iterator is released. If you keep the iterator in a variable, such as `$events`, the connection therefore stays open until you release the variable, e.g. with `unset($events)`.*

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

*Note that each row returned by the iterator matches the projection specified in your query, e.g. an associative array for an event, or a number for `COUNT()`.*

*Note that EventSourcingDB sends a heartbeat every second while there is nothing else to send. If neither a row nor a heartbeat arrives for the [stall timeout](#configuring-the-stall-timeout), which is 30 seconds by default, e.g. because the connection stalled, the iterator closes the connection and throws a `ConnectionStalledException`.*

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

*Note that EventSourcingDB sends a heartbeat every second while there are no events to send. If neither an event nor a heartbeat arrives for the [stall timeout](#configuring-the-stall-timeout), which is 30 seconds by default, e.g. because the connection stalled, the iterator closes the connection and throws a `ConnectionStalledException`.*

*Note that EventSourcingDB never ends an observation on its own, other than with an error, which the iterator throws as a `DbApiException`, e.g. with the `errorCode` `ErrorCode::SERVER_SHUTTING_DOWN` once a server that offers the v2 API shuts down. If its response ends cleanly nonetheless, e.g. because a proxy ends it, the iterator throws a `RuntimeException` that says the stream broke off, and if the connection breaks, it throws a `RuntimeException` that says so. Only `abortIn` and leaving the loop end an observation without an exception.*

*Note that if you leave the `foreach` loop early, e.g. with `break`, the connection is closed once the iterator is released. If you keep the iterator in a variable, such as `$events`, the connection therefore stays open until you release the variable, e.g. with `unset($events)`.*

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

If you need to abort observing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. It applies only to the iteration currently going on, or, if there is none, to the next one, so later requests of the client are not aborted:

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

If a schema is already registered for the event type, the function throws a `DbApiException` whose `errorCode` is `ErrorCode::EVENT_SCHEMA_ALREADY_REGISTERED`.

### Listing Subjects

To list subjects, call the `readSubjects` function with a subject and an options object, in which you have to set the `recursive` option. To list all subjects, provide `/` as the subject and set `recursive` to `true`, since all subjects are nested under the root subject. The function returns an asynchronous iterator, which you can use e.g. inside a `foreach` loop:

```php
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;

$subjects = $client->readSubjects(
  '/',
  new ReadSubjectsOptions(
    recursive: true,
  ),
);

foreach($subjects as $subject) {
  // ...
}
```

If you only want to list subjects within a specific branch, provide the desired subject instead. The listing includes the subject itself:

```php
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;

$subjects = $client->readSubjects(
  '/books',
  new ReadSubjectsOptions(
    recursive: true,
  ),
);

foreach($subjects as $subject) {
  // ...
}
```

If you set `recursive` to `false`, the listing only includes the given subject, if it exists, and none of the subjects nested under it.

#### Aborting Listing

If you need to abort listing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. It applies only to the iteration currently going on, or, if there is none, to the next one, so later requests of the client are not aborted:

```php
use Thenativeweb\Eventsourcingdb\ReadSubjectsOptions;

$subjects = $client->readSubjects(
  '/',
  new ReadSubjectsOptions(
    recursive: true,
  ),
);

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

Each event type is an `EventType` instance, whose `type` property holds the name of the event type, whose `hasEvents` property tells whether events of the type have been written, rather than only a schema registered for it, and whose `schema` property holds the registered schema, or an empty array if there is none.

#### Aborting Listing

If you need to abort listing use `abortIn` before or within the `foreach` loop. The `abortIn` method expects the abort time in seconds. It applies only to the iteration currently going on, or, if there is none, to the next one, so later requests of the client are not aborted:

```php
$eventTypes = $client->readEventTypes();

$client->abortIn(0.1);
foreach($eventTypes as $eventType) {
  // ...
  $client->abortIn(0.1);
}
```

### Listing a Specific Event Type

To list a specific event type, call the `readEventType` function with the event type as an argument. The function returns the detailed event type, which includes the schema:

```php
$eventType = $client->readEventType('io.eventsourcingdb.library.book-acquired');
```

If the event type does not exist, the function throws a `DbApiException` whose `errorCode` is `ErrorCode::EVENT_TYPE_UNKNOWN`.

### Handling Errors

If the server reports an error, the client throws a `DbApiException`, which extends `RuntimeException`. It has the following properties:

- `errorCode` is the code of the error exactly as the server sends it, which is the URL of the page that documents the error, such as `https://docs.eventsourcingdb.io/errors/precondition-violated`, or an empty string for an error without a code. It is called `errorCode` because PHP reserves `code` and `getCode()` for the integer code of every exception.
- `description` is the text that describes the error.
- `details` is an associative array with the details of the error, or an empty array if there are none.
- `status` is the HTTP status of the response. Note that the v2 API reports an error of a request, such as a precondition that does not hold, in the body of a response with the status `200`, so tell errors apart by their `errorCode` rather than by their status.
- `retryAfter` is, for a request that was limited, whose status is `429`, the number of seconds after which it may be repeated, if the server names them, and `null` otherwise. The client never repeats a request on its own.

The `ErrorCode` class has a constant for every code, so that you can tell errors apart without comparing strings:

```php
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\ErrorCode;

try {
  $eventType = $client->readEventType('io.eventsourcingdb.library.book-acquired');
} catch (DbApiException $exception) {
  if ($exception->errorCode === ErrorCode::EVENT_TYPE_UNKNOWN) {
    // ...
  }
}
```

If a stream breaks off, i.e. its response ends cleanly, but before the server ended the stream, the iterator throws a `RuntimeException` that says so. This holds for a stream of the v2 API whose response ends without the item that ends the stream, and for an observation, which the server never ends, other than with an error. A connection that breaks fails a stream with a `RuntimeException` as well.

If the server only offers the v1 API, the client assigns the code of the v2 API to those errors of the v1 API that clearly match one, such as a precondition that does not hold, a schema that is already registered, or an event type that does not exist. Other errors of the v1 API keep an empty code.

#### Knowing Whether a Write Was Stored

EventSourcingDB never stores a write halfway, but a write whose answer is lost may have been stored nonetheless. So, if writing events or registering an event schema fails in a way that leaves open whether the server stored it, the client throws an `OutcomeUnknownException`, which extends `RuntimeException`, and whose previous exception, which `getPrevious()` returns, is the cause. This is the case, e.g., if the connection stalls or breaks after the request was sent, if `abortIn` aborts reading the answer, if the answer breaks off, if the server fails unexpectedly, or if the answer does not come from EventSourcingDB, e.g. from a proxy.

A write certainly was not stored if the client throws any other exception, i.e. if the connection could not be established at all, or if the server refused the write, which it reports with a `DbApiException`:

```php
use Thenativeweb\Eventsourcingdb\OutcomeUnknownException;

try {
  $writtenEvents = $client->writeEvents([
    // events
  ]);
} catch (OutcomeUnknownException $exception) {
  // Find out whether the events were written, e.g. by reading them,
  // before writing them again.
}
```

### Verifying an Event's Hash

To verify the integrity of an event, call the `verifyHash` function on the event instance. This recomputes the event's hash locally and compares it to the hash stored in the event. If the hashes differ, the function throws a `HashVerificationFailedException`:

```php
$event->verifyHash();
```

The function supports both versions of the hash: version 1, which events written before EventSourcingDB 1.3 keep, and version 2, which starts with `esdb:hash:v2:`. If the hash is of a version the client SDK does not know, the function throws an `UnsupportedHashVersionException`, whose message names the version.

*Note that both exceptions extend `RuntimeException`, so code that catches a `RuntimeException` keeps working.*

*Note that an event returned by the client keeps its data exactly as the server sent it, to verify the hash. It therefore does not compare equal, e.g. with `==` or `assertEquals`, to an event you build by hand from the same fields. Compare their fields instead.*

*Note that this only verifies the hash. If you also want to verify the signature, you can skip this step and call `verifySignature` directly, which performs a hash verification internally.*

### Verifying an Event's Signature

To verify the authenticity of an event, call the `verifySignature` function on the event instance. This requires the public key that matches the private key used for signing on the server.

The function first verifies the event's hash, and then checks the signature. If the hash verification fails, it throws one of the exceptions described above. If the signature is invalid, it throws a `SignatureVerificationFailedException`, which extends `RuntimeException` as well:

```php
$verificationKey = // an ed25519 public key

$event->verifySignature($verificationKey);
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

If you want to sign events, call the `withSigningKey` function. This generates a new signing and verification key pair inside the container:

```php
$container = new Container()
  ->withSigningKey();
```

You can retrieve the private key (for signing) and the public key (for verifying signatures) once the container has been started:

```php
$signingKey = $container->getSigningKey();
$verificationKey = $container->getVerificationKey();
```

The `signingKey` is the private key EventSourcingDB signs events with. The `verificationKey` can be passed to `verifySignature` when verifying events read from the database.

The `start` function pulls the image if it is missing, and then waits until the database in the container answers a ping, for 30 seconds at most, not counting the pull. To change this time, call the `withStartupTimeout` function with the seconds:

```php
$container = new Container()
  ->withStartupTimeout(60);
```

If a container keeps running, but does not become reachable in time, `start` replaces it with a new one, up to three containers in total, and then throws a `RuntimeException`. If Docker fails, or if the container exits, `start` throws a `RuntimeException` at once, which, if the container exited, contains the last 20 lines of its log. In any case, `start` removes every container that it failed to start.

#### Configuring the Client Manually

In case you need to set up the client yourself, use the following functions to get details on the container:

- `getHost()` returns the host name
- `getMappedPort()` returns the port
- `getBaseUrl()` returns the full URL of the container
- `getApiToken()` returns the API token

## Upgrading From Version 1

Version 2 of the client SDK speaks the v2 API of EventSourcingDB wherever the server offers it, and the v1 API otherwise, see [Talking to Servers With and Without the v2 API](#talking-to-servers-with-and-without-the-v2-api). It changes the following:

- **Event types follow the v2 API.** `EventType::$eventType` is now `EventType::$type`, and `EventType::$isPhantom` is replaced by `EventType::$hasEvents`, which is its opposite. The constructor and `jsonSerialize` follow suit:

  ```php
  // Before
  if ($eventType->isPhantom) { /* ... */ }
  new EventType(eventType: 'io.eventsourcingdb.library.book-acquired', isPhantom: false, schema: []);

  // After
  if (!$eventType->hasEvents) { /* ... */ }
  new EventType(type: 'io.eventsourcingdb.library.book-acquired', hasEvents: true, schema: []);
  ```

- **`readSubjects` takes options with a required `recursive` option**, and its first parameter is called `subject` rather than `baseSubject`. The previous version always listed recursively, so `recursive: true` keeps that:

  ```php
  // Before
  $subjects = $client->readSubjects('/books');

  // After
  $subjects = $client->readSubjects('/books', new ReadSubjectsOptions(recursive: true));
  ```

- **Every error that the server reports is a `DbApiException`**, which extends `RuntimeException`, as the exceptions thrown before did, see [Handling Errors](#handling-errors). Before, its `getCode()` returned the HTTP status. Now the code of the error, such as `ErrorCode::PRECONDITION_VIOLATED`, is in `errorCode`, the status is in `status`, and `getCode()` returns 0. An error that the server reports within a stream is a `DbApiException` as well, with the status `200`, as is every error of a request that the v2 API reports:

  ```php
  // Before
  try { /* ... */ } catch (RuntimeException $exception) {
    if ($exception->getCode() === 409) { /* ... */ }
  }

  // After
  try { /* ... */ } catch (DbApiException $exception) {
    if ($exception->errorCode === ErrorCode::PRECONDITION_VIOLATED) { /* ... */ }
  }
  ```

- **The messages of errors that the server reports have changed.** The message of an error that the v1 API reports within a stream starts with the scope of the call, as the message of every other error does, and the message of an error that the server answers with a status no longer ends with the line break that the server sends. If the server sends no text at all, the message names the status. `description` holds the text of the server alone:

  ```php
  // Before
  $exception->getMessage(); // 'failed to read events'
  $exception->getMessage(); // "Failed to write events, state conflict: precondition failed\n"
  $exception->getMessage(); // 'Failed to write events, '

  // After
  $exception->getMessage(); // 'Failed to read events, failed to read events'
  $exception->getMessage(); // 'Failed to write events, state conflict: precondition failed'
  $exception->getMessage(); // 'Failed to write events, the server answered with status 500.'
  $exception->description;  // 'state conflict: precondition failed'
  ```

- **`HeartbeatTimeoutException` is removed.** Observing events and running EventQL queries throw a `ConnectionStalledException` once neither data nor a heartbeat has arrived for the stall timeout, as every other request does, and with its message:

  ```php
  // Before
  try { /* ... */ } catch (HeartbeatTimeoutException $exception) {
    $exception->getMessage(); // 'No event and no heartbeat arrived for 30 seconds.'
  }

  // After
  try { /* ... */ } catch (ConnectionStalledException $exception) {
    $exception->getMessage(); // 'No data arrived from the server for 30 seconds.'
  }
  ```

- **A write that may have been stored throws an `OutcomeUnknownException`**, whose previous exception is the cause, rather than the cause itself, see [Knowing Whether a Write Was Stored](#knowing-whether-a-write-was-stored). This applies to `writeEvents` and `registerEventSchema`:

  ```php
  // Before
  try { $client->writeEvents($events); } catch (ConnectionStalledException $exception) { /* ... */ }

  // After
  try { $client->writeEvents($events); } catch (OutcomeUnknownException $exception) {
    $cause = $exception->getPrevious(); // e.g. a ConnectionStalledException
  }
  ```

- **A write through the v1 API whose answer `abortIn` aborts throws an `OutcomeUnknownException`**, rather than return an empty array, or, if a part of the answer had arrived, throw that it cannot be read, since the server may have stored the write:

  ```php
  // Before
  $client->abortIn(0.1);
  $writtenEvents = $client->writeEvents($events); // [], if nothing of the answer had arrived

  // After
  $client->abortIn(0.1);
  try { $client->writeEvents($events); } catch (OutcomeUnknownException $exception) { /* ... */ }
  ```

- **An observation whose response ends cleanly without an error throws a `RuntimeException`**, since EventSourcingDB never ends an observation on its own, rather than ending quietly. This holds for both APIs. A connection that breaks threw before, and still does, and `abortIn` and leaving the loop still end an observation quietly:

  ```php
  // Before
  foreach ($client->observeEvents('/books', $options) as $event) { /* ... */ }
  // The loop ended quietly once the response ended, e.g. because a proxy ended it.

  // After
  try {
    foreach ($client->observeEvents('/books', $options) as $event) { /* ... */ }
  } catch (RuntimeException $exception) {
    // The observation broke off, e.g. because a proxy ended its response.
  }
  ```

- **`Stream::withHeartbeatTimeout()` is removed**, the constructor of `Stream` no longer takes `$hasHeartbeatTimeout`, and `CurlMultiHandler::contentIterator()` no longer takes `$withHeartbeatTimeout`, since there is no heartbeat timeout any more:

  ```php
  // Before
  $stream = new Stream($curlMultiHandler, hasHeartbeatTimeout: true);
  $stream = $stream->withHeartbeatTimeout();
  $chunks = $curlMultiHandler->contentIterator(withHeartbeatTimeout: true);

  // After
  $stream = new Stream($curlMultiHandler);
  $chunks = $curlMultiHandler->contentIterator();
  ```
