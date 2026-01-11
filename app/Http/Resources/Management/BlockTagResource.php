<?php
namespace App\Http\Resources\Management;

use App\Models\Space\BlockTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlockTag
 */
class BlockTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'external_id' => $this->external_id,
            'icon' => $this->icon,
            'color' => $this->color,
            'blocks_count' => $this->whenCounted('blocks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
