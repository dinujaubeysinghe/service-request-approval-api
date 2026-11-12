<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property ActivityAction $action
 * @property RequestStatus|null $from_status
 * @property RequestStatus|null $to_status
 */
class RequestActivity extends Model
{
    /** The log is append-only, so there is no updated_at column. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'service_request_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'comment',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'from_status' => RequestStatus::class,
            'to_status' => RequestStatus::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The activity log is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('The activity log is append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
