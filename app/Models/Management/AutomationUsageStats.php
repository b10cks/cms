<?php

namespace App\Models\Management;

use App\Enums\PeriodType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\AutomationUsageStats
 *
 * @property string $id
 * @property string $automation_id
 * @property PeriodType $period_type
 * @property \Illuminate\Support\Carbon $period_date
 * @property int $total_executions
 * @property int $successful_executions
 * @property int $failed_executions
 * @property float|null $avg_duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Automation $automation
 * @method static \Database\Factories\Management\AutomationUsageStatsFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereAutomationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereAvgDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereFailedExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats wherePeriodDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats wherePeriodType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereSuccessfulExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereTotalExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationUsageStats whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class AutomationUsageStats extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    protected $table = 'automation_usage_stats';

    protected $fillable = [
        'automation_id',
        'period_type',
        'period_date',
        'total_executions',
        'successful_executions',
        'failed_executions',
        'avg_duration_ms'
    ];

    protected $casts = [
        'period_type' => PeriodType::class,
        'period_date' => 'date',
        'total_executions' => 'integer',
        'successful_executions' => 'integer',
        'failed_executions' => 'integer',
        'avg_duration_ms' => 'float'
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id', 'id');
    }
}
