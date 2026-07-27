<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin SpaceConnection
 */
class SpaceConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'driver' => $this->driver,
            // `config` is cast to encrypted:array and holds the space
            // database's host, username and password. It decrypts on access,
            // so serializing it would hand those credentials to any caller.
            'config' => Arr::only($this->config ?? [], ['driver', 'host', 'port', 'database']),
            'settings' => $this->settings,
            'is_default' => $this->is_default,
            'tables_count' => $this->whenCounted('tables', fn () => $this->tables_count),
            'bases_count' => $this->whenCounted('bases', fn () => $this->tables_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
