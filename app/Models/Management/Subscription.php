<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\Subscription
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $plan_id
 * @property string $name
 * @property string|null $lemon_squeezy_id
 * @property string|null $ls_customer_id
 * @property string|null $billing_portal_url
 * @property string $status
 * @property string $variant_id
 * @property string $product_id
 * @property int $quantity
 * @property array<array-key, mixed>|null $quotas
 * @property \Illuminate\Support\Carbon|null $renews_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property array<array-key, mixed>|null $attributes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Space $space
 * @property-read \App\Models\Management\Plan|null $plan
 *
 * @method static \Database\Factories\Management\SubscriptionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereAttributes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereLemonSqueezyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereRenewsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereTrialEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereVariantId($value)
 *
 * @mixin \Eloquent
 */
class Subscription extends GlobalModel
{
    use Auditable;
    use HasFactory;
    use HasUlids;

    protected $table = 'subscriptions';

    protected $fillable = [
        'space_id',
        'plan_id',
        'name',
        'lemon_squeezy_id',
        'ls_customer_id',
        'billing_portal_url',
        'status',
        'variant_id',
        'product_id',
        'quantity',
        'quotas',
        'renews_at',
        'ends_at',
        'trial_ends_at',
        'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
        'quotas' => 'array',
        'renews_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function isActive(): bool
    {
        return \in_array($this->status, ['active', 'on_trial'], true);
    }

    public function isFree(): bool
    {
        return $this->lemon_squeezy_id === null && $this->ls_customer_id === null;
    }

    public function effectiveQuotas(): array
    {
        return $this->quotas ?? $this->plan?->quotas ?? [];
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'on_trial']);
    }
}
