<?php

namespace App\Models\Space;

use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $block_id
 * @property string|null $created_by_id
 * @property string $name
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $description
 * @property array<array-key, mixed> $content
 * @property string|null $preview_file
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Block $block
 * @property-read \App\Models\User|null $createdBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereCreatedById($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate wherePreviewFile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockTemplate withoutTrashed()
 *
 * @mixin \Eloquent
 */
class BlockTemplate extends SpaceModel
{
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    protected $table = 'block_templates';

    protected string $spaceChannel = 'blocks';

    protected $fillable = [
        'space_id',
        'created_by_id',
        'name',
        'icon',
        'color',
        'description',
        'content',
        'preview_file',
    ];

    protected $casts = [
        'content' => 'array',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id', 'id');
    }

    /**
     * Template queries are keyed by block on the frontend; the broadcast has
     * to carry the parent id so listeners can target the right caches.
     *
     * @return array<string, mixed>
     */
    public function broadcastContext(): array
    {
        return ['block_id' => $this->block_id];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_id', 'id');
    }
}
