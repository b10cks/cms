<?php

namespace App\Models\Space;

use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsSpaceModelEvents;
use App\Models\Traits\SpaceAuditable;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $data_source_id
 * @property string $key
 * @property string $value
 * @property array<array-key, mixed>|null $dimensions
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Space\DataSource|null $dataSource
 * @method static \Database\Factories\Space\DataEntryFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereDataSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereDimensions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataEntry whereValue($value)
 * @mixin \Eloquent
 */
class DataEntry extends SpaceModel
{
    use Auditable;
    use BroadcastsSpaceModelEvents;
    use Filterable;
    use HasFactory;
    use HasUlids;
    use SpaceAuditable;

    protected $table = 'data_entries';

    protected string $spaceChannel = 'data_sources';

    protected $fillable = [
        'external_id',
        'key',
        'value',
        'dimensions',
        'is_active',
    ];

    protected $casts = [
        'dimensions' => 'json',
        'is_active' => 'boolean',
    ];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id', 'id');
    }

    /**
     * Entry queries are keyed by data source on the frontend; the broadcast
     * has to carry the parent id so listeners can target the right caches.
     *
     * @return array<string, mixed>
     */
    public function broadcastContext(): array
    {
        return ['data_source_id' => $this->data_source_id];
    }
}
