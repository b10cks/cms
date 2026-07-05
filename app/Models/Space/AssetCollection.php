<?php

namespace App\Models\Space;

use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A named set of assets. 'manual' collections hold explicit items
 * (asset_collection_items), 'smart' collections are evaluated from the stored
 * filter `rules` at read time.
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property string $type
 * @property array<array-key, mixed>|null $rules
 * @property string|null $cover_asset_id
 * @property array<array-key, mixed>|null $settings
 * @property string|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AssetCollectionItem> $items
 * @property-read int|null $items_count
 * @property-read Asset|null $coverAsset
 * @property-read User|null $createdBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollection filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollection query()
 *
 * @mixin \Eloquent
 */
class AssetCollection extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SMART = 'smart';

    protected string $spaceChannel = 'assets';

    protected $table = 'asset_collections';

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'icon',
        'color',
        'type',
        'rules',
        'cover_asset_id',
        'settings',
        'created_by_id',
    ];

    protected $casts = [
        'rules' => 'array',
        'settings' => 'array',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetCollectionItem::class, 'collection_id', 'id')->orderBy('position');
    }

    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }

    public function isSmart(): bool
    {
        return $this->type === self::TYPE_SMART;
    }
}
