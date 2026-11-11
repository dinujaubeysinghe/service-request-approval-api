<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\RequestStatus;
use RuntimeException;

final class InvalidTransitionException extends RuntimeException
{
    public static function between(RequestStatus $from, RequestStatus $to): self
    {
        return new self(sprintf("Cannot move a request from '%s' to '%s'.", $from->value, $to->value));
    }

    public static function missingManager(): self
    {
        return new self('The requester has no manager, so the request cannot be submitted for approval.');
    }
}
