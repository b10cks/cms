<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon $hour_timestamp Start of the hour
 * @property int $hit_count
 * @property int $unique_ips Approximate unique IPs in this hour
 * @property int $success_count 2xx responses
 * @property int $error_count 4xx/5xx responses
 * @property array<array-key, mixed>|null $status_code_distribution Count per status code
 * @property int $time_taken_sum Sum of time-taken in ms for requests in this hour
 * @property int|null $time_taken Average time taken in ms per request
 * @property string $space_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Space $space
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereErrorCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereHitCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereHourTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereStatusCodeDistribution($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereSuccessCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereTimeTaken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereTimeTakenSum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereUniqueIps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceApiHitHourly whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
