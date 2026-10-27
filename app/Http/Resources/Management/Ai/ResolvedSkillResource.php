<?php

namespace App\Http\Resources\Management\Ai;

use App\Services\Ai\Skills\ResolvedSkill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ResolvedSkill $resource */
class ResolvedSkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manifest = $this->resource->manifest;

        return [
            'name' => $manifest->name,
            'description' => $manifest->description,
            'surfaces' => $manifest->surfaces,
            'blocks' => $manifest->blocks,
            'output' => $manifest->output,
            'source' => $this->resource->source->value,
            'owner_id' => $this->resource->ownerId,
            'skill_id' => $this->resource->skillId,
            'shadows' => $this->resource->shadows,
        ];
    }
}
