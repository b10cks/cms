<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\JsonResource;

class PersonalAccessTokenListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
        ];
    }
}
