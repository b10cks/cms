<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Storage;
use App\Services\Storage\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


/**
 * @mixin Storage
 */
class StorageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'state' => $this->state,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'driver' => $this->driver,
            'settings' => $this->settings,
            'is_default' => $this->is_default,
            'is_managed' => $this->is_managed,
            'files_count' => $this->whenCounted('files'),
            'capabilities' => $this->when(isset($this->driver), function () {
                return app(StorageService::class)->getDriverCapabilities($this->driver);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
