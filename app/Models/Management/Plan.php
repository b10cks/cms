<?php

namespace App\Models\Management;

use App\Models\Traits\TranslatedAttribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Management\Plan
 *
 * @property string $id
 * @property array $name
 * @property array|null $description
 * @property array|null $features
 * @property string $price
 * @property string|null $yearly_price
 * @property string $period
 * @property array|null $quotas
 * @property string|null $ls_product_id
 * @property string|null $ls_variant_id
 * @property string|null $ls_variant_id_yearly
 * @property string|null $contact_url
 * @property bool $is_free
 * @property bool $is_recommended
 * @property bool $is_public
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class Plan extends GlobalModel
{
    use HasUlids;
    use TranslatedAttribute;

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'description',
        'features',
        'price',
        'yearly_price',
        'period',
        'quotas',
        'ls_product_id',
        'ls_variant_id',
        'ls_variant_id_yearly',
        'contact_url',
        'is_free',
        'is_recommended',
        'is_public',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'features' => 'array',
        'quotas' => 'array',
        'price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'is_free' => 'boolean',
        'is_recommended' => 'boolean',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Spaces a non-public (custom/subsidized) plan has been granted to. */
    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'plan_space')->withTimestamps();
    }

    public function supportsYearly(): bool
    {
        return $this->yearly_price !== null;
    }

    /** The LemonSqueezy variant that sells the given billing interval. */
    public function variantIdForInterval(string $interval): ?string
    {
        return $interval === 'year' ? $this->ls_variant_id_yearly : $this->ls_variant_id;
    }

    /** Price for the given billing interval (yearly falls back to null when unsupported). */
    public function priceForInterval(string $interval): ?string
    {
        return $interval === 'year' ? $this->yearly_price : $this->price;
    }

    public function isAvailableForSpace(Space $space): bool
    {
        return $this->is_public
            || $this->spaces()->whereKey($space->id)->exists();
    }

    public function getTranslatedName(): ?string
    {
        return $this->getJsonTranslatedValue('name');
    }

    public function getTranslatedDescription(): ?string
    {
        return $this->getJsonTranslatedValue('description');
    }

    public function getTranslatedFeatures(): array
    {
        $value = $this->getJsonTranslatedValue('features');

        return \is_array($value) ? $value : [];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
