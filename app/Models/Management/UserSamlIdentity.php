<?php

namespace App\Models\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSamlIdentity extends GlobalModel
{
    use HasUlids;

    protected $table = 'user_saml_identities';

    protected $fillable = [
        'team_saml_provider_id',
        'user_id',
        'external_id',
        'name_id',
        'session_index',
        'last_login_at',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(TeamSamlProvider::class, 'team_saml_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
