<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream\MockClass;

use Thenativeweb\Eventsourcingdb\Stream\MessageTrait;

class MockMessageTrait
{
    use MessageTrait;

    public function __construct(
        array $headers = [],
    ) {
        $this->parseHeaders($headers);
    }
}
