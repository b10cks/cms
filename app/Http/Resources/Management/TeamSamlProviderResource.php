<?php

namespace App\Http\Resources\Management;

use App\Models\Management\TeamSamlProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TeamSamlProvider */
class TeamSamlProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'enabled' => $this->enabled,
            'idp_entity_id' => $this->idp_entity_id,
            'sso_url' => $this->sso_url,
            'slo_url' => $this->slo_url,
            'idp_x509_cert' => $this->idp_x509_cert,
            'sp_x509_cert' => $this->sp_x509_cert,
            'has_sp_private_key' => filled($this->sp_private_key),
            'name_id_format' => $this->name_id_format,
            'attribute_mapping' => $this->attribute_mapping,
            'role_attribute' => $this->role_attribute,
            'role_mapping' => $this->role_mapping,
            'default_role' => $this->default_role,
            'allow_jit' => $this->allow_jit,
            'strict' => $this->strict,
            'sign_authn_requests' => $this->sign_authn_requests,
            'sign_logout_requests' => $this->sign_logout_requests,
            'want_assertions_signed' => $this->want_assertions_signed,
            'want_messages_signed' => $this->want_messages_signed,
            'want_assertions_encrypted' => $this->want_assertions_encrypted,
            'digest_algorithm' => $this->digest_algorithm,
            'signature_algorithm' => $this->signature_algorithm,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'links' => [
                'login_url' => route('auth.saml.redirect', ['team' => $this->team_id]),
                'acs_url' => route('auth.saml.acs', ['team' => $this->team_id]),
                'sls_url' => route('auth.saml.sls', ['team' => $this->team_id]),
                'metadata_url' => route('auth.saml.metadata', ['team' => $this->team_id]),
                'sp_entity_id' => route('auth.saml.metadata', ['team' => $this->team_id]),
            ],
        ];
    }
}
