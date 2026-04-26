<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamSamlProvider extends GlobalModel
{
    use HasUlids;

    protected $table = 'team_saml_providers';

    protected $fillable = [
        'team_id',
        'enabled',
        'idp_entity_id',
        'sso_url',
        'slo_url',
        'idp_x509_cert',
        'sp_x509_cert',
        'sp_private_key',
        'name_id_format',
        'attribute_mapping',
        'role_attribute',
        'role_mapping',
        'default_role',
        'allow_jit',
        'strict',
        'sign_authn_requests',
        'sign_logout_requests',
        'want_assertions_signed',
        'want_messages_signed',
        'want_assertions_encrypted',
        'digest_algorithm',
        'signature_algorithm',
        'last_login_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'attribute_mapping' => 'array',
        'role_mapping' => 'array',
        'allow_jit' => 'boolean',
        'strict' => 'boolean',
        'sign_authn_requests' => 'boolean',
        'sign_logout_requests' => 'boolean',
        'want_assertions_signed' => 'boolean',
        'want_messages_signed' => 'boolean',
        'want_assertions_encrypted' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserSamlIdentity::class, 'team_saml_provider_id');
    }

    protected function idpX509Cert(): Attribute
    {
        return $this->encryptedCertificateAttribute();
    }

    protected function spX509Cert(): Attribute
    {
        return $this->encryptedCertificateAttribute();
    }

    protected function spPrivateKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? decrypt($value) : null,
            set: fn (?string $value) => $value ? encrypt(trim($value)) : null,
        );
    }

    private function encryptedCertificateAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? decrypt($value) : null,
            set: fn (?string $value) => $value ? encrypt($this->normalizePem($value)) : null,
        );
    }

    private function normalizePem(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        $body = trim(str_replace(["\r", "\n", ' '], '', $value));

        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($body, 64, "\n")
            .'-----END CERTIFICATE-----';
    }
}
