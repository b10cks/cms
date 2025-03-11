<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\Management\SpaceConnection
 *
 * @property string $id
 * @property string $space_id
 * @property string $state
 * @property string|null $name
 * @property string|null $description
 * @property string $driver
 * @property array<array-key, mixed>|null $config
 * @property array<array-key, mixed>|null $settings
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $make_purified_attribute
 * @property-read \App\Models\Management\Space $space
 * @method static \Database\Factories\Management\SpaceConnectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereDriver($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceConnection withoutTrashed()
 * @mixin \Eloquent
 */
class SpaceConnection extends GlobalModel
{
    use Auditable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'space_connections';

    protected $casts = [
        'config' => 'encrypted:array',
        'is_default' => 'boolean',
        'settings' => 'array',
    ];

    protected $fillable = [
        'name',
        'state',
        'slug',
        'description',
        'driver',
        'config',
        'settings',
        'is_default',
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
