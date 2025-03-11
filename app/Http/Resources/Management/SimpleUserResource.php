<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class SimpleUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar_url,
            'initials' => $this->initials,
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String()
        ];
    }
}
