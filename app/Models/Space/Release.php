<?php

namespace App\Models\Space;

use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string|null $external_id
 * @property string|null $name
 * @property string|null $description
 * @property array<array-key, mixed>|null $settings
 * @property string|null $owner_id
 * @property \Illuminate\Support\Carbon|null $publish_at
 * @property \Illuminate\Support\Carbon|null $committed_at
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\ContentVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereCommittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereOwnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release wherePublishAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Release withoutTrashed()
 * @mixin \Eloquent
 */
class Release extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    protected $table = 'releases';

    protected $casts = [
        'external_id' => 'string',
        'committed_at' => 'datetime',
        'publish_at' => 'datetime',
        'published_at' => 'datetime',
        'settings' => 'array',
    ];

    protected $fillable = [
        'name',
        'description',
        'external_id',
        'publish_at',
        'settings',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class, 'release_id', 'id');
    }
}
