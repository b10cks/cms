<?php

namespace App\Http\Resources\Management;

use App\Models\Space\CommentReaction;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommentReaction
 */
class CommentReactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'comment_id' => $this->comment_id,
            'emoji' => $this->emoji,
            'author' => new SimpleUserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
