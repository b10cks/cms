<?php

namespace App\Models\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment request: someone with billing rights (typically an agency) picks a
 * plan for the space and asks a client-side contact to complete the checkout,
 * so the client becomes the LemonSqueezy customer and owns the billing
 * relationship. At most one open proposal exists per space.
 *
 * @property string $id
 * @property string $space_id
 * @property string $plan_id
 * @property string $billing_interval
 * @property string $invited_email
 * @property string|null $created_by
 * @property string|null $invite_id
 * @property string $status
 * @property Carbon|null $resolved_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Space $space
 * @property-read Plan $plan
 * @property-read User|null $creator
 * @property-read Invite|null $invite
 *
 * @mixin \Eloquent
 */
class PlanProposal extends GlobalModel
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'space_id',
        'plan_id',
        'billing_interval',
        'invited_email',
        'created_by',
        'invite_id',
        'status',
        'resolved_at',
        'expires_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(Invite::class, 'invite_id', 'id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Flip an overdue open proposal to expired. Returns the fresh state. */
    public function resolveExpiry(): self
    {
        if ($this->status === self::STATUS_OPEN && $this->isExpired()) {
            $this->update(['status' => self::STATUS_EXPIRED, 'resolved_at' => now()]);
        }

        return $this;
    }
}
