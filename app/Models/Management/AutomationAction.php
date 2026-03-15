<?php

namespace App\Models\Management;

use App\Http\Resources\Management\AutomationActionResource;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Services\Automation\Enums\ActionType;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $space_id
 * @property string $name
 * @property string|null $description
 * @property ActionType $type
 * @property array<array-key, mixed>|null $config
 * @property array<array-key, mixed>|null $secrets
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_executed_at
 * @property string|null $last_execution_status
 * @property string|null $last_execution_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Automation> $automations
 * @property-read int|null $automations_count
 * @property-read Space $space
 *
 * @mixin \Eloquent
 */
class AutomationAction extends GlobalModel
{
    use Auditable;
    use BroadcastsModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'automation_actions';

    protected ?string $broadcastResource = AutomationActionResource::class;

    protected $fillable = [
        'name',
        'description',
        'type',
        'config',
        'secrets',
        'is_active',
        'last_executed_at',
        'last_execution_status',
        'last_execution_error',
    ];

    protected $casts = [
        'type' => ActionType::class,
        'config' => 'array',
        'secrets' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_executed_at' => 'datetime',
    ];

    public function getAuditRedactedFields(): array
    {
        return ['secrets'];
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

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class, 'action_id', 'id');
    }
}
