<?php

namespace App\Http\Resources\Management;

use App\Models\Management\ProviderNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProviderNote
 */
class ProviderNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'icon' => $this->icon,
            'url' => $this->url,
            'color' => $this->color,
            'content' => $this->content,
            'is_pinned' => $this->is_pinned,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
