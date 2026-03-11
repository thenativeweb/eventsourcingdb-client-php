<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

enum BoundType: string
{
    case INCLUSIVE = 'inclusive';
    case EXCLUSIVE = 'exclusive';
}
