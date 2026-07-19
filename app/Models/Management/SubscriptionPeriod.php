<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\Management\SubscriptionPeriod
 *
 * A contiguous billing cycle on a single plan for a space. One row is opened
 * when a plan activates and closed (with usage rollups) when the plan switches,
 * renews, or the subscription lapses — giving a persistent history of what was
 * used while each plan was running.
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $subscription_id
 * @property string|null $plan_id
 * @property string $plan_name
 * @property array<array-key, mixed>|null $quotas
 * @property string $price
 * @property string $billing_period
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $renews_at
 * @property Carbon|null $ended_at
 * @property string|null $close_reason
 * @property int|null $storage_bytes
 * @property int|null $traffic_bytes
 * @property string|null $ai_spend_usd
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Space $space
 * @property-read Subscription|null $subscription
 * @property-read Plan|null $plan
 *
 * @mixin \Eloquent
 */
class SubscriptionPeriod extends GlobalModel
{
    use HasUlids;

    protected $table = 'subscription_periods';

    protected $fillable = [
        'space_id',
        'subscription_id',
        'plan_id',
        'plan_name',
        'quotas',
        'price',
        'billing_period',
        'status',
        'started_at',
        'renews_at',
        'ended_at',
        'close_reason',
        'storage_bytes',
        'traffic_bytes',
        'ai_spend_usd',
    ];

    protected $casts = [
        'quotas' => 'array',
        'price' => 'decimal:2',
        'started_at' => 'datetime',
        'renews_at' => 'datetime',
        'ended_at' => 'datetime',
        'storage_bytes' => 'integer',
        'traffic_bytes' => 'integer',
        'ai_spend_usd' => 'decimal:6',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /** The currently running period (not yet closed). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
