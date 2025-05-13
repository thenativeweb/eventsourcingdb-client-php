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
  // ...
], [
  new IsSubjectPristine('/books/42'),
]);
```

#### Using the `isSubjectOnEventId` precondition

If you only want to write events in case the last event of a subject (such as `/books/42`) has a specific ID (e.g., `0`), import the `IsSubjectOnEventId` class, use it to create a precondition, and pass it in an array as the second argument:

```php
use Thenativeweb\Eventsourcingdb\IsSubjectOnEventId;

$writtenEvents = $client->writeEvents([
  // ...
], [
  new IsSubjectOnEventId('/books/42', '0'),
]);
```

*Note that according to the CloudEvents standard, event IDs must be of type string.*

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
$container = new Container()
  ->withImageTag('1.0.0');
```

Similarly, you can configure the port to use and the API token. Call the `withPort` or the `withApiToken` function respectively:

```php
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
