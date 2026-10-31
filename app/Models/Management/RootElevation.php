<?php

namespace App\Models\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A time-boxed grant of root write powers, started on purpose with a reason.
 *
 * @property string $id
 * @property string $user_id
 * @property string $reason
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class RootElevation extends GlobalModel
{
    use HasUlids;

    public const DURATIONS = [15, 30, 60];

    public const DEFAULT_DURATION = 30;

    protected $fillable = ['user_id', 'reason', 'expires_at', 'ended_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
