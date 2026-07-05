<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hourly per-space download bandwidth rollup for asset-package downloads
 * served via the CloudFront /dl/ path (mirrors SpaceTrafficUsageHourly).
 *
 * @property string $id
 * @property Carbon $hour_timestamp Start of the hour
 * @property int $bytes_sent Total bytes sent to clients
 * @property int $request_count Number of download requests
 * @property string $space_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Space $space
 *
 * @mixin \Eloquent
 */
class SpaceDownloadUsageHourly extends GlobalModel
{
    use Filterable;
    use HasUlids;

    protected $table = 'space_download_usage_hourly';

    protected $fillable = [
        'space_id',
        'hour_timestamp',
        'bytes_sent',
        'request_count',
    ];

    protected $casts = [
        'hour_timestamp' => 'datetime',
        'bytes_sent' => 'integer',
        'request_count' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }
}
