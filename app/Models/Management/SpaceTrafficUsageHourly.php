<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon $hour_timestamp Start of the hour
 * @property int $bytes_sent Total bytes sent to clients
 * @property int $bytes_received Total bytes received from clients
 * @property int|null $total_bytes Total bytes processed (sent + received)
 * @property int $request_count Number of traffic requests
 * @property int $cache_hits
 * @property int $cache_misses
 * @property string $space_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Management\Space $space
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereBytesReceived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereBytesSent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereCacheHits($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereCacheMisses($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereHourTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereRequestCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereTotalBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceTrafficUsageHourly whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SpaceTrafficUsageHourly extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    protected $table = 'space_traffic_usage_hourly';

    protected $fillable = [
        'space_id',
        'hour_timestamp',
        'bytes_sent',
        'bytes_received',
        'total_bytes',
        'request_count',
        'cache_hits',
        'cache_misses',
    ];

    protected $casts = [
        'hour_timestamp' => 'datetime',
        'bytes_sent' => 'integer',
        'bytes_received' => 'integer',
        'total_bytes' => 'integer',
        'request_count' => 'integer',
        'cache_hits' => 'integer',
        'cache_misses' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function getCacheHitRate(): float
    {
        $total = $this->cache_hits + $this->cache_misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($this->cache_hits / $total) * 100, 2);
    }

    public function getFormattedBytesSent(): string
    {
        return $this->formatBytes($this->bytes_sent);
    }

    public function getFormattedBytesReceived(): string
    {
        return $this->formatBytes($this->bytes_received);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}
