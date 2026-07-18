<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Management\SpaceUsageAlert
 *
 * One row per fired usage alert: a given space is notified at most once per
 * metric/threshold within one allowance window (`period_key`, e.g. "2026-07").
 * The unique index makes firstOrCreate the idempotency gate.
 *
 * @property string $id
 * @property string $space_id
 * @property string $metric
 * @property int $threshold
 * @property string $period_key
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Space $space
 *
 * @mixin \Eloquent
 */
class SpaceUsageAlert extends GlobalModel
{
    use HasUlids;

    protected $table = 'space_usage_alerts';

    protected $fillable = [
        'space_id',
        'metric',
        'threshold',
        'period_key',
    ];

    protected $casts = [
        'threshold' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }
}
