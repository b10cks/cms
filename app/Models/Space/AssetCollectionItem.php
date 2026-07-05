<?php

namespace App\Models\Space;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership row of a manual asset collection. Mirrors the `asset_versions`
 * pattern: no `updated_at`, only `created_at` is set.
 *
 * @property string $id
 * @property string $collection_id
 * @property string $asset_id
 * @property int $position
 * @property string|null $added_by_id
 * @property Carbon|null $created_at
 * @property-read AssetCollection|null $collection
 * @property-read Asset|null $asset
 * @property-read User|null $addedBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollectionItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollectionItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetCollectionItem query()
 *
 * @mixin \Eloquent
 */
class AssetCollectionItem extends SpaceModel
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'asset_collection_items';

    protected $fillable = [
        'collection_id',
        'asset_id',
        'position',
        'added_by_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(AssetCollection::class, 'collection_id', 'id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id', 'id');
    }
}
