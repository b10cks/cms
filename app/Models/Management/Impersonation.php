<?php

namespace App\Models\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "sign in as" session of a root operator. Browser sessions point at the
 * row from the session; API sessions through `token_id`.
 *
 * @property string $id
 * @property string $impersonator_id
 * @property string $user_id
 * @property int|null $token_id
 * @property string $reason
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $impersonator
 * @property-read User $user
 */
class Impersonation extends GlobalModel
{
    use HasUlids;

    public const LIFETIME_MINUTES = 60;

    protected $fillable = ['impersonator_id', 'user_id', 'token_id', 'reason', 'expires_at', 'ended_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
