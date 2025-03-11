<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\Subscription
 *
 * @property string $id
 * @property string $space_id
 * @property string $name
 * @property string $lemon_squeezy_id
 * @property string $status
 * @property string $variant_id
 * @property string $product_id
 * @property int $quantity
 * @property \Illuminate\Support\Carbon|null $renews_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property array<array-key, mixed>|null $attributes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Space $space
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
 * @mixin \Eloquent
 */
class Subscription extends GlobalModel
{
    use Auditable;
    use HasUlids;
    use HasFactory;

    protected $table = 'subscriptions';

    protected $casts = [
        'attributes' => 'array',
        'renews_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }
}
