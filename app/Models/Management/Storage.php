<?php

namespace App\Models\Management;

use App\Casts\Slug;
use App\Models\Space\Asset;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
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
 * @property string $space_id
 * @property string $state
 * @property string|null $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $description
 * @property string $driver
 * @property array<array-key, mixed>|null $config
 * @property array<array-key, mixed>|null $settings
 * @property bool $is_default
 * @property bool $is_managed
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $make_purified_attribute
 * @property-read \App\Models\Management\Space $space
 * @method static \Database\Factories\Management\StorageFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereDriver($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereIsManaged($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Storage withoutTrashed()
 * @mixin \Eloquent
 */
class Storage extends GlobalModel
{
    use Auditable;
//    use BroadcastsModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'storages';

    protected $fillable = [
        'name',
        'slug',
        'state',
        'icon',
        'color',
        'description',
        'driver',
        'config',
        'settings',
        'is_default',
        'is_managed'
    ];

    protected $casts = [
        'slug' => Slug::class,
        'config' => 'encrypted:array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_managed' => 'boolean'
    ];

    public function getAuditRedactedFields(): array
    {
        return ['config'];
    }

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }
}
