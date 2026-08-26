<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceBlueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpaceBlueprint
 */
class SpaceBlueprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'settings' => $this->settings ?? [],
            // Named `snapshot`, not `data`: a top-level `data` key makes
            // Laravel treat the payload as already wrapped, so the response
            // would come back without its `data` envelope.
            'snapshot' => $this->data ?? [],
            'team_id' => $this->team_id,
            'team' => $this->whenLoaded('team', fn() => new TeamResource($this->team)),
            'created_by' => $this->whenLoaded('creator', fn() => new SimpleUserResource($this->creator)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
