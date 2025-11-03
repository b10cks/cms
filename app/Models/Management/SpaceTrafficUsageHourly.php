<?php

namespace App\Models\Management;

use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
