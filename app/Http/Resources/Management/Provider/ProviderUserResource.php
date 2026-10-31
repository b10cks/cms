<?php

namespace App\Http\Resources\Management\Provider;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the provider user directory.
 *
 * @mixin User
 */
class ProviderUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim($this->name) !== '' ? trim($this->name) : $this->email,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'initials' => $this->initials,
            'status' => match (true) {
                $this->is_root => 'root',
                $this->suspended_at !== null => 'suspended',
                default => 'active',
            },
            'is_root' => $this->is_root,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'teams_count' => (int) ($this->teams_count ?? 0),
            'first_team' => $this->whenLoaded('teams', fn () => $this->teams->first()?->name),
            'sign_in' => $this->signInMethods(),
        ];
    }

    /** @return array<int, string> */
    private function signInMethods(): array
    {
        return array_values(array_filter([
            $this->password !== null ? 'password' : null,
            $this->two_factor_enabled_at !== null ? '2fa' : null,
            ($this->saml_identities_count ?? 0) > 0 ? 'saml' : null,
            ...($this->relationLoaded('socialLinks') ? $this->socialLinks->pluck('service')->unique()->all() : []),
        ]));
    }
}
