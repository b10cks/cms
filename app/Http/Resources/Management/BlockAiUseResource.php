<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Block;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Block
 */
class BlockAiUseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type,
            'schema' => $this->schema->toArray(),
            'tags' => $this->tags,
        ];
    }
}
