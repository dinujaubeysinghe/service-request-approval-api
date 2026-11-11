<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    /**
     * Status, manager and timestamps are only ever set by server-side code
     * (the workflow service), never copied straight from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'requester_id',
        'manager_id',
        'assignee_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'submitted_at',
        'decided_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'due_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'priority' => RequestPriority::class,
            'category' => RequestCategory::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<RequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RequestComment::class)->orderBy('id');
    }

    /** @return HasMany<RequestActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(RequestActivity::class)->orderBy('id');
    }
}
