<?php

use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Client;

class ClientTest extends TestCase
{
    public function testPingReturnsSuccessfully(): void
    {
        $client = new Client('http://localhost:3000', 'secret');

        $this->expectNotToPerformAssertions();
    }
}
