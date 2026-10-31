<?php

namespace App\Http\Resources\Management;

use App\Models\Management\TeamDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TeamDomain */
class TeamDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'domain' => $this->domain,
            // Unicode form of an IDN, for display.
            'display_domain' => idn_to_utf8($this->domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $this->domain,
            'txt_record' => $this->txtRecord(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'policy' => $this->policy->value,
            'default_role' => $this->defaultRoleKey(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
