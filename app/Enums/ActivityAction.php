<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Assigned = 'assigned';
    case Started = 'started';
    case Completed = 'completed';
    case Commented = 'commented';
}
