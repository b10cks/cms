<?php

namespace App\Models\Space;

use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;
use Staudenmeir\EloquentJsonRelations\Relations\HasManyJson;

/**
 *
 *
 * @property string $name
 * @property string|null $external_id
 * @property string|null $icon
 * @property string|null $color
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Space\Block[] $blocks
 * @property-read int|null $blocks_count
 * @method static \Database\Factories\Space\BlockTagFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTag whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class BlockTag extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use HasPurifiedAttributes;
    use Filterable;
    use HasJsonRelationships;
    use HasFactory;
    use SpaceAuditable;

    protected string $spaceChannel = 'blocks';

    protected $table = 'block_tags';
    protected $primaryKey = 'name';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'external_id',
        'icon',
        'color',
    ];

    protected static function booted(): void
    {
        static::deleting(function (BlockTag $tag) {
            $tag->blocks()->each(function (Block $block) use ($tag) {
                $block->update([
                    'tags' => array_values(array_diff($block->tags ?? [], [$tag->name])),
                ]);
            });
        });
    }

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    public function blocks(): HasManyJson
    {
        return $this->hasManyJson(Block::class, 'tags');
    }
}
