<?php

namespace App\Models\Management;

use App\Models\Traits\TranslatedAttribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Management\Plan
 *
 * @property string $id
 * @property array $name
 * @property array|null $description
 * @property array|null $features
 * @property string $price
 * @property string $period
 * @property array|null $quotas
 * @property string|null $ls_product_id
 * @property string|null $ls_variant_id
 * @property string|null $contact_url
 * @property bool $is_free
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
        'period',
        'quotas',
        'ls_product_id',
        'ls_variant_id',
        'contact_url',
        'is_free',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'features' => 'array',
        'quotas' => 'array',
        'price' => 'decimal:2',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
}
