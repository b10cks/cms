<?php

namespace App\Http\Resources\Management;

use App\Models\Space\BlockTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlockTemplate
 */
class BlockTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'content' => $this->content,
            'preview_file' => $this->preview_file,
            'created_by' => $this->whenLoaded('createdBy', fn () => new SimpleUserResource($this->createdBy)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
