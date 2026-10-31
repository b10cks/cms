<?php

namespace App\Models\Management;

use App\Enums\TeamDomainPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $domain
 * @property string $verification_token
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property TeamDomainPolicy $policy
 * @property string|null $default_role_id
 */
class TeamDomain extends GlobalModel
{
    use HasUlids;

    public const string TXT_PREFIX = 'b10cks-verification=';

    protected $table = 'team_domains';

    protected $fillable = [
        'team_id',
        'domain',
        'verification_token',
        'policy',
        'default_role_id',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'policy' => TeamDomainPolicy::class,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** The TXT record value the owner publishes on the domain. */
    public function txtRecord(): string
    {
        return self::TXT_PREFIX.$this->verification_token;
    }

    public function defaultRoleKey(): string
    {
        return $this->defaultRole?->key ?? 'member';
    }
}
