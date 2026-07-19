<?php

namespace App\Models\Management;

use App\Enums\SubscriptionStatus;

use App\Jobs\Space\ReconcileSubscriptionPeriods;
use App\Jobs\Space\SyncSpaceAiKey;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string $billing_interval
 * @property array<array-key, mixed>|null $quotas
 * @property Carbon|null $renews_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $trial_ends_at
 * @property array<array-key, mixed>|null $attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Space $space
 * @property-read Plan|null $plan
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
        'billing_interval',
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

    protected static function booted(): void
    {
        // Keep the space's OpenRouter key in sync with its subscription state:
        // provision on activation, reconcile limits on plan change, revoke when
        // it lapses. Deferred until after commit so it runs outside the
        // webhook/sync transaction.
        static::saved(function (Subscription $subscription): void {
            if (! $subscription->space_id) {
                return;
            }

            $relevant = ['status', 'plan_id', 'quotas', 'lemon_squeezy_id', 'renews_at', 'ends_at'];

            if (! $subscription->wasRecentlyCreated && ! $subscription->wasChanged($relevant)) {
                return;
            }

            SyncSpaceAiKey::dispatch($subscription->space_id)->afterCommit();
            ReconcileSubscriptionPeriods::dispatch($subscription->space_id)->afterCommit();

            // A newly entitlement-granting paid subscription fulfils any open
            // payment request (agency flow) — regardless of which plan the
            // payer ended up choosing.
            if (in_array($subscription->status, SubscriptionStatus::activeValues(), true)
                && ! ($subscription->plan?->is_free ?? true)) {
                PlanProposal::open()->where('space_id', $subscription->space_id)->update([
                    'status' => PlanProposal::STATUS_ACCEPTED,
                    'resolved_at' => now(),
                ]);
            }
        });
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * Whether the subscription currently grants its plan's entitlements.
     *
     * Beyond the plainly active statuses this includes two grace states:
     * - cancelled but paid through the period (`ends_at` in the future) — a
     *   LemonSqueezy cancellation keeps the subscription until period end;
     * - past_due — LemonSqueezy is retrying the payment (dunning); it resolves
     *   to active on recovery or unpaid/expired on final failure.
     */
    public function isActive(): bool
    {
        if (\in_array($this->status, SubscriptionStatus::activeValues(), true)) {
            return true;
        }

        if ($this->status === SubscriptionStatus::PastDue->value) {
            return true;
        }

        return $this->status === SubscriptionStatus::Cancelled->value
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    /** Cancelled but still entitled until period end — eligible for resume. */
    public function isCancelledWithGrace(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled->value
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    public function isFree(): bool
    {
        return $this->lemon_squeezy_id === null && $this->ls_customer_id === null;
    }

    /**
     * A paid, live subscription: backed by a LemonSqueezy subscription and in
     * an active state. This is the gate for provisioning paid-plan resources.
     */
    public function isPaid(): bool
    {
        return $this->lemon_squeezy_id !== null && $this->isActive();
    }

    /**
     * The quotas the space is entitled to. `quotas` on the subscription is a
     * custom override (subsidized/negotiated limits) and wins when set; plans
     * provide the defaults. Null quota values mean unlimited.
     */
    public function effectiveQuotas(): array
    {
        return $this->quotas ?? $this->plan?->quotas ?? [];
    }

    /** SQL twin of {@see isActive()}, including the grace states. */
    public function scopeActive($query)
    {
        return $query->where(function ($query) {
            $query
                ->whereIn('status', [
                    ...SubscriptionStatus::activeValues(),
                    SubscriptionStatus::PastDue->value,
                ])
                ->orWhere(fn ($query) => $query
                    ->where('status', SubscriptionStatus::Cancelled->value)
                    ->where('ends_at', '>', now()));
        });
    }
}
