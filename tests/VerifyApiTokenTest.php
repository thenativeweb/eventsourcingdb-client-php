<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ClientTestTrait;

final class VerifyApiTokenTest extends TestCase
{
    use ClientTestTrait;

    public function testDoesNotThrowAnErrorIfTheTokenIsValid(): void
    {
        $client = $this->container->getClient();
        $client->verifyApiToken();
        $this->expectNotToPerformAssertions();
    }

    public function testThrowsAnErrorIfTheTokenIsInvalid(): void
    {
        $baseUrl = $this->container->getBaseUrl();
        $apiToken = $this->container->getApiToken() . '-invalid';
        $client = new Client($baseUrl, $apiToken);

        try {
            $client->verifyApiToken();
            $this->fail('Expected verifying the API token to fail, but it did not.');
        } catch (DbApiException $dbApiException) {
            $this->assertSame(401, $dbApiException->status);
            $this->assertSame('', $dbApiException->code);
            $this->assertSame('unauthorized', $dbApiException->description);
            $this->assertSame('Failed to verify API token, unauthorized', $dbApiException->getMessage());
        }
    }
}
