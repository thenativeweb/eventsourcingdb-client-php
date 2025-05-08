# eventsourcingdb

The official PHP client SDK for [EventSourcingDB](https://www.eventsourcingdb.io) – a purpose-built database for event sourcing.

EventSourcingDB enables you to build and operate event-driven applications with native support for writing, reading, and observing events. This client SDK provides convenient access to its capabilities in Go.

For more information on EventSourcingDB, see its [official documentation](https://docs.eventsourcingdb.io/).

This client SDK includes support for [Testcontainers](https://testcontainers.com/) to spin up EventSourcingDB instances in integration tests. For details, see [Using Testcontainers](#using-testcontainers).

## Getting Started

Install the client SDK:

```shell
composer require thenativeweb/eventsourcingdb
```

Import the package and create an instance by providing the URL of your EventSourcingDB instance and the API token to use:

```php
require __DIR__ . '/vendor/autoload.php';

use Thenativeweb\Eventsourcingdb\Client;

$client = new Client('http://localhost:3000', 'secret');
```

Then call the `ping` function to check whether the instance is reachable. If it is not, the function will throw an error:

```php
$client->ping();
```

*Note that `ping` does not require authentication, so the call may succeed even if the API token is invalid.*

If you want to verify the API token, call `verifyApiToken`. If the token is invalid, the function will throw an error:

```php
$client->verifyApiToken();
```
