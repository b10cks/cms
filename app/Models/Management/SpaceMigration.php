<?php

namespace App\Models\Management;

use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaceMigration extends GlobalModel
{
    use Filterable;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'space_migrations';

    protected $fillable = [
        'source_space_id',
        'target_space_id',
        'created_by',
        'state',
        'progress',
        'scope',
        'conflict_strategy',
        'stats',
        'result',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'stats' => 'array',
        'result' => 'array',
        'progress' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function sourceSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'source_space_id', 'id');
    }

    public function targetSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'target_space_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->state === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'state' => 'processing',
            'started_at' => now(),
            'progress' => 0,
        ]);
    }

    public function markAsCompleted(array $stats, array $result): void
    {
        $this->update([
            'state' => 'completed',
            'stats' => $stats,
            'result' => $result,
            'completed_at' => now(),
            'progress' => 100,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'state' => 'failed',
            'error_message' => $errorMessage,
            'failed_at' => now(),
        ]);
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(99, max(0, $progress))]);
    }
}
