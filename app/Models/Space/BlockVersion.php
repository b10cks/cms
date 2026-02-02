<?php

namespace App\Models\Space;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $block_id
 * @property string|null $parent_id
 * @property string|null $created_by_id
 * @property array<array-key, mixed> $data
 * @property string|null $commit_message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Space\Block $block
 * @property-read \App\Models\Space\BlockVersion|null $parent
 * @property-read \App\Models\User|null $createdBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereCommitMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereCreatedById($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockVersion whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class BlockVersion extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    protected $table = 'block_versions';

    protected $fillable = [
        'block_id',
        'parent_id',
        'created_by_id',
        'data',
        'commit_message',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_id', 'id');
    }
}
