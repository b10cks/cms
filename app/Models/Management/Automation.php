<?php

namespace App\Models\Management;

use App\Http\Resources\Management\AutomationResource;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\ValueObjects\Trigger;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * App\Models\Management\Automation
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $name
 * @property string|null $description
 * @property string $action_id
 * @property Trigger|null $trigger
 * @property TriggerType $trigger_type
 * @property array<array-key, mixed>|null $trigger_config
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
 * @property-read AutomationAction $action
 * @property-read \App\Models\Management\Space $space
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\AutomationUsageStats> $usageStats
 * @property-read int|null $usage_stats_count
 * @method static \Database\Factories\Management\AutomationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereActionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereExecutionCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereExecutionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereLastTriggeredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereTriggerConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Automation whereTriggerType($value)
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
        'action_id',
        'trigger',
        'is_active',
        'execution_limit',
        'execution_count',
        'last_triggered_at',
    ];

    protected $casts = [
        'trigger_type' => TriggerType::class,
        'trigger_config' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    protected function trigger(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): ?Trigger {
                $type = $attributes['trigger_type'] ?? null;
                if (! $type) {
                    return null;
                }

                $config = $attributes['trigger_config'] ?? null;
                if (\is_string($config)) {
                    $config = json_decode($config, true);
                }

                return new Trigger(
                    $type instanceof TriggerType ? $type : TriggerType::from($type),
                    \is_array($config) ? $config : null,
                );
            },
            set: function (mixed $value): array {
                if ($value instanceof Trigger) {
                    return [
                        'trigger_type' => $value->type()->value,
                        'trigger_config' => $value->config() === null
                            ? null
                            : json_encode($value->config(), JSON_THROW_ON_ERROR),
                    ];
                }

                if (\is_array($value)) {
                    $trigger = Trigger::fromArray($value);

                    if (! $trigger) {
                        throw new InvalidArgumentException('Invalid trigger payload.');
                    }

                    return [
                        'trigger_type' => $trigger->type()->value,
                        'trigger_config' => $trigger->config() === null
                            ? null
                            : json_encode($trigger->config(), JSON_THROW_ON_ERROR),
                    ];
                }

                throw new InvalidArgumentException('Invalid trigger value type.');
            },
        );
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AutomationAction::class, 'action_id', 'id');
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
