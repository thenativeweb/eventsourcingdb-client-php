<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

enum ReadIfEventIsMissing: string
{
    case READ_NOTHING = 'read-nothing';
    case READ_EVERYTHING = 'read-everything';
}
