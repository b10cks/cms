<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Comment;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    public function toArray($request): array
    {
        $reactions = [];
        if ($this->relationLoaded('reactions')) {
            $reactions = $this->reactions
                ->groupBy('emoji')
                ->map(function ($group) {
                    return $group->map(fn($reaction) => new SimpleUserResource($reaction->author));
                })
                ->toArray();
        }

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'content_id' => $this->content_id,
            'content_version_id' => $this->content_version_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'is_resolved' => $this->is_resolved,
            'item_id' => $this->item_id,
            'field' => $this->field,
            'position' => $this->position,
            'author' => new SimpleUserResource($this->whenLoaded('author')),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            'reactions' => $reactions,
            'mentions' => SimpleUserResource::collection($this->whenLoaded('mentions')),
            'replies_count' => $this->whenCounted('replies'),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
