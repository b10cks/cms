<?php

namespace App\Models\Space;

use App\Models\Traits\HasPurifiedAttributes;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetTag withoutTrashed()
 * @mixin \Eloquent
 */
class AssetTag extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'asset_tags';

    protected $fillable = [
        'name',
        'icon',
        'color',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }
}
