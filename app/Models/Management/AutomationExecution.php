<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\AutomationExecution
 *
 * @property string $id
 * @property string $automation_id
 * @property string $status
 * @property array<array-key, mixed>|null $context
 * @property array<array-key, mixed>|null $result
 * @property string|null $error
 * @property float|null $duration
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Management\Automation $automation
 * @method static \Database\Factories\Management\AutomationExecutionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereAutomationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationExecution whereStatus($value)
 * @mixin \Eloquent
 */
class AutomationExecution extends GlobalModel
{
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'automation_executions';

    protected $fillable = [
        'automation_id',
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

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id', 'id');
    }

    public function setCompletedAtAttribute($value): void
    {
        $this->attributes['completed_at'] = $value;
        $this->attributes['duration'] = $this->started_at->diffInMilliseconds($value);
    }
}
