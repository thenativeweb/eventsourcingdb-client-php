<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

interface ResponseInterface extends PsrResponseInterface
{
    public function getStream(): StreamInterface;
}
