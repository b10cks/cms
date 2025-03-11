<?php

namespace App\Models\Management;

use App\Enums\PeriodType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\TokenUsageStats
 *
 * @property string $id
 * @property string $token_id
 * @property PeriodType $period_type
 * @property \Illuminate\Support\Carbon $period_date
 * @property int $total_executions
 * @property int $successful_executions
 * @property int $failed_executions
 * @property float|null $avg_duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Token $baseToken
 * @property-read \App\Models\Management\Token $token
 * @method static \Database\Factories\Management\TokenUsageStatsFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereAvgDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereFailedExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats wherePeriodDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats wherePeriodType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereSuccessfulExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereTokenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereTotalExecutions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenUsageStats whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class TokenUsageStats extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    protected $table = 'token_usage_stats';

    protected $fillable = [
        'token_id',
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

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'token_id', 'id');
    }

    public function baseToken(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'token_id', 'id');
    }
}
