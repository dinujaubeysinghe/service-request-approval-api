<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestActivity */
final class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'comment' => $this->comment,
            'metadata' => $this->metadata,
            'actor' => $this->whenLoaded('actor', fn () => new UserResource($this->actor)),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
