<?php

namespace App\Models\Space;

use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $parent_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\Block> $blocks
 * @property-read int|null $blocks_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlockFolder> $children
 * @property-read int|null $children_count
 * @property-read BlockFolder|null $parent
 * @method static \Database\Factories\Space\BlockFolderFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockFolder whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class BlockFolder extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SpaceAuditable;

    protected $table = 'block_folders';

    protected $fillable = [
        'external_id',
        'name',
        'icon',
        'color',
        'description',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'folder_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BlockFolder::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BlockFolder::class, 'parent_id', 'id');
    }
}
