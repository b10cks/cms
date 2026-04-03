<?php

namespace App\Models\Space;

use App\Models\Management\Storage;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string|null $name
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $parent_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\Asset> $assets
 * @property-read int|null $assets_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AssetFolder> $children
 * @property-read int|null $children_count
 * @property string|null $make_purified_attribute
 * @property-read AssetFolder|null $parent
 * @property-read Storage|null $storage
 * @method static \Database\Factories\Space\AssetFolderFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetFolder withoutTrashed()
 * @mixin \Eloquent
 */
class AssetFolder extends SpaceModel
{
    use Filterable;
    use HasUlids;
    use HasFactory;
    use SoftDeletes;
    use HasPurifiedAttributes;
    use SpaceAuditable;

    protected $table = 'asset_folders';

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'icon',
        'color',
        'storage_id',
        'parent_id',
        'settings',
    ];

    protected $casts = [
        'settings' => AssetFolderSettings::class,
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AssetFolder::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AssetFolder::class, 'parent_id', 'id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'folder_id', 'id');
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }
}
