<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Asset;
use App\Services\Storage\AssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'filename' => $this->filename,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'full_path' => $this->full_path,
            'folder_id' => $this->folder_id,
            'metadata' => $this->metadata,
            'data' => $this->data && count($this->data) ? $this->data : new \StdClass(),
            'tags' => $this->tags,
            'url' => app(AssetService::class)->getAssetUrl($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
