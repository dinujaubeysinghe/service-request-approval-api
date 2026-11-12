<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * The only statuses this status may move to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::Returned],
            self::Returned => [self::Submitted],
            self::Approved => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::Completed],
            self::Rejected, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Rejected and Completed requests can never change again. */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Employees may edit a request only in these statuses. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /**
     * The statuses an Operations user can see.
     *
     * @return list<self>
     */
    public static function visibleToOperations(): array
    {
        return [self::Approved, self::Assigned, self::InProgress, self::Completed];
    }
}
