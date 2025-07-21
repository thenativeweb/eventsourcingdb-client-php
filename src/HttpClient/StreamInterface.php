<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;


interface StreamInterface extends \IteratorAggregate, \Stringable
{
    public function getIterator(): \Traversable;
    public function getContents(): string;
}
