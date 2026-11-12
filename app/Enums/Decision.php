<?php

declare(strict_types=1);

namespace App\Enums;

enum Decision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case SendBack = 'return';

    public function toStatus(): RequestStatus
    {
        return match ($this) {
            self::Approve => RequestStatus::Approved,
            self::Reject => RequestStatus::Rejected,
            self::SendBack => RequestStatus::Returned,
        };
    }
}
