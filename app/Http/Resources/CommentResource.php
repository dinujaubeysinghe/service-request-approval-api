<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RequestComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestComment */
final class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => new UserResource($this->author)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
