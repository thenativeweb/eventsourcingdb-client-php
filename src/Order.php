<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

enum Order: string
{
    case CHRONOLOGICAL = 'chronological';
    case ANTICHRONOLOGICAL = 'antichronological';
}
