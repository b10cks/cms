<?php

namespace App\Http\Resources\User;

use App\Models\User\UserPasskey;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserPasskey */
class UserPasskeyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'aaguid' => $this->aaguid,
            'created_at' => $this->created_at,
            'last_used_at' => $this->last_used_at,
        ];
    }
}
