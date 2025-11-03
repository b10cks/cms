<?php

namespace App\Models\Management;

use App\Casts\Automation\ActionCast;
use App\Casts\Automation\TriggerCast;
use App\Http\Resources\Management\AutomationResource;
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
 * App\Models\Management\Automation
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $name
 * @property string|null $description
 * @property \App\Services\Automation\ValueObjects\Trigger|null $trigger
 * @property \App\Services\Automation\ValueObjects\Action|null $action
 * @property array<array-key, mixed>|null $secrets
 * @property int|null $execution_limit
 * @property int $execution_count
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_triggered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\AutomationExecution> $executions
 * @property-read int|null $executions_count
 * @property string|null $make_purified_attribute
 * @property-read \App\Models\Management\Space $space
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\AutomationUsageStats> $usageStats
 * @property-read int|null $usage_stats_count
 * @method static \Database\Factories\Management\AutomationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereExecutionCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereExecutionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereLastTriggeredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereSecrets($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereTrigger($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation withoutTrashed()
 * @mixin \Eloquent
 */
class Automation extends GlobalModel
{
    use Auditable;
    use BroadcastsModelEvents;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'automations';

    protected ?string $broadcastResource = AutomationResource::class;

    protected $fillable = [
        'name',
        'description',
        'trigger',
        'action',
        'secrets',
        'is_active',
        'execution_limit',
        'execution_count',
        'last_triggered_at',
    ];

    protected $casts = [
        'trigger' => TriggerCast::class,
        'action' => ActionCast::class,
        'is_active' => 'boolean',
        'secrets' => 'encrypted:array',
        'last_triggered_at' => 'datetime',
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

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'automation_id', 'id');
    }

    public function usageStats(): HasMany
    {
        return $this->hasMany(AutomationUsageStats::class, 'automation_id', 'id');
    }
}
