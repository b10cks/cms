<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Management\TokenExecution
 *
 * @property string $id
 * @property string $token_id
 * @property string $status
 * @property array<array-key, mixed>|null $context
 * @property array<array-key, mixed>|null $result
 * @property string|null $error
 * @property float|null $duration
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TokenExecution> $executions
 * @property-read int|null $executions_count
 * @property-read \App\Models\Management\Token $token
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\TokenUsageStats> $usageStats
 * @property-read int|null $usage_stats_count
 * @method static \Database\Factories\Management\TokenExecutionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TokenExecution whereTokenId($value)
 * @mixin \Eloquent
 */
class TokenExecution extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'token_executions';

    protected $fillable = [
        'token_id',
        'status',
        'context',
        'result',
        'error',
        'started_at',
        'completed_at',
        'created_at'
    ];

    protected $casts = [
        'context' => 'array',
        'result' => 'array',
        'duration' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'token_id', 'id');
    }

    public function setCompletedAtAttribute($value): void
    {
        $this->attributes['completed_at'] = $value;
        $this->attributes['duration'] = $this->started_at->diffInMilliseconds($value);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TokenExecution::class, 'token_id', 'id');
    }

    public function usageStats(): HasMany
    {
        return $this->HasMany(TokenUsageStats::class, 'token_id', 'id');
    }
}
