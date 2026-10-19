<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Thenativeweb\Eventsourcingdb\Api\V1Transport;
use Thenativeweb\Eventsourcingdb\Api\V2Transport;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

/**
 * Proves which API the client speaks with the database of the image that the
 * tests run against, so that a client that fell back to the v1 API, which
 * EventSourcingDB 1.3 still offers, does not go unnoticed.
 */
final class ApiSelectionTest extends TestCase
{
    use ClientTestTrait;

    public function testUsesTheApiThatTheDatabaseOffers(): void
    {
        $offersV2Api = $this->askWhetherTheDatabaseOffersTheV2Api();

        $this->client->ping();

        $transportSelector = (new ReflectionProperty($this->client, 'transportSelector'))->getValue($this->client);
        $transport = (new ReflectionProperty($transportSelector, 'transport'))->getValue($transportSelector);

        $this->assertInstanceOf($offersV2Api ? V2Transport::class : V1Transport::class, $transport);
    }

    /**
     * Asks the database for the ping of the v2 API on its own, without the
     * client, which a database that offers the v2 API answers with 200.
     */
    private function askWhetherTheDatabaseOffersTheV2Api(): bool
    {
        $curlHandle = curl_init($this->container->getBaseUrl() . '/api/v2/system/ping');
        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($curlHandle);
        $this->assertIsString($body, curl_error($curlHandle));

        $status = curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $this->assertContains($status, [200, 404, 405], "Expected the database to answer the ping of the v2 API with 200, 404, or 405, but it answered with {$status}.");

        return $status === 200;
    }
}
