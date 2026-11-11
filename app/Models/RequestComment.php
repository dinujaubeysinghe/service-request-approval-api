<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RequestCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestComment extends Model
{
    /** @use HasFactory<RequestCommentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'service_request_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
