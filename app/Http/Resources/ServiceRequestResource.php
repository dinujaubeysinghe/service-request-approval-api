<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceRequest */
final class ServiceRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category->value,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'manager_id' => $this->manager_id,
            'requester' => $this->whenLoaded('requester', fn () => new UserResource($this->requester)),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager === null ? null : new UserResource($this->manager)),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : new UserResource($this->assignee)),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
