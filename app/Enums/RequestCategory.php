<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestCategory: string
{
    case ItSupport = 'it_support';
    case Equipment = 'equipment';
    case Maintenance = 'maintenance';
    case Access = 'access';
}
