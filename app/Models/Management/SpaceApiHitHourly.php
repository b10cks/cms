<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceApiHitHourly extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    protected $table = 'space_api_hits_hourly';

    protected $fillable = [
        'space_id',
        'hour_timestamp',
        'hit_count',
        'unique_ips',
        'success_count',
        'error_count',
        'status_code_distribution',
        'time_taken',
    ];

    protected $casts = [
        'hour_timestamp' => 'datetime',
        'status_code_distribution' => 'array',
        'hit_count' => 'integer',
        'unique_ips' => 'integer',
        'success_count' => 'integer',
        'error_count' => 'integer',
        'time_taken_sum' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function incrementHits(
        int $count = 1,
        ?int $statusCode = null,
        ?float $timeTaken = null
    ): void {
        $this->increment('hit_count', $count);

        if ($statusCode) {
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->increment('success_count', $count);
            } elseif ($statusCode >= 400) {
                $this->increment('error_count', $count);
            }

            $distribution = $this->status_code_distribution ?? [];
            $distribution[$statusCode] = ($distribution[$statusCode] ?? 0) + $count;
            $this->status_code_distribution = $distribution;
        }

        if ($timeTaken !== null) {
            $this->time_taken = ($this->time_taken ?? 0) + $timeTaken;
        }

        $this->save();
    }

    public function getSuccessRate(): float
    {
        if ($this->hit_count === 0) {
            return 0.0;
        }

        return round(($this->success_count / $this->hit_count) * 100, 2);
    }

    public function getErrorRate(): float
    {
        if ($this->hit_count === 0) {
            return 0.0;
        }

        return round(($this->error_count / $this->hit_count) * 100, 2);
    }
}
