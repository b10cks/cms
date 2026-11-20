<?php

namespace App\Models\User;

use App\Models\Management\GlobalModel;
use App\Models\User;
use App\Services\Auth\PasskeyService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A WebAuthn credential (passkey) registered to a user. The credential itself
 * lives in `record`, serialized by {@see PasskeyService}.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_hash
 * @property string $record
 * @property string|null $aaguid
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class UserPasskey extends GlobalModel
{
    use HasUlids;

    protected $table = 'user_passkeys';

    protected $fillable = ['user_id', 'name', 'credential_hash', 'record', 'aaguid', 'last_used_at'];

    protected $hidden = ['record', 'credential_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
