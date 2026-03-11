<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

enum ObserveIfEventIsMissing: string
{
    case READ_EVERYTHING = 'read-everything';
    case WAIT_FOR_EVENT = 'wait-for-event';
}
