<?php

namespace App\Models\Space;

use App\Casts\Slug;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
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
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array<array-key, mixed>|null $dimensions
 * @property array<array-key, mixed>|null $settings
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Space\DataEntry> $entries
 * @property-read int|null $entries_count
 * @property string|null $make_purified_attribute
 * @method static \Database\Factories\Space\DataSourceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereDimensions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataSource whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class DataSource extends SpaceModel
{
    use Auditable;
//    use BroadcastsModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SpaceAuditable;

    protected $table = 'data_sources';

    protected $fillable = [
        'external_id',
        'name',
        'slug',
        'description',
        'dimensions',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'slug' => Slug::class,
        'dimensions' => 'json',
        'settings' => 'json',
        'is_active' => 'boolean',
    ];

    /**
     * Get the purified description attribute.
     */
    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    /**
     * Get the entries for this data source.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(DataEntry::class, 'data_source_id', 'id');
    }
}
