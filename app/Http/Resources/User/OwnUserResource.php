<?php

namespace App\Http\Resources\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class OwnUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'email_verified_at' => $this->when(!!$this->email_verified_at, fn() => $this->email_verified_at->toIso8601String()),
            'login_count' => $this->login_count ?? 0,
            'last_Login_at' => $this->when(!!$this->last_login_at, fn() => $this->last_login_at->toIso8601String()),
            'language_iso' => $this->preferredLocale(),
            'settings' => $this->settings->toArray(),
        ];
    }
}
