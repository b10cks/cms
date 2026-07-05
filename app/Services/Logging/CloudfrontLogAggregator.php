<?php

namespace App\Services\Logging;

use App\Models\Management\SpaceApiHitHourly as ApiHitHourly;
use App\Models\Management\SpaceDownloadUsageHourly as DownloadUsageHourly;
use App\Models\Management\SpaceTrafficUsageHourly as TrafficUsageHourly;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CloudfrontLogAggregator
{
    private array $apiHitsBuffer = [];

    private array $trafficBuffer = [];

    private array $downloadBuffer = [];

    private int $bufferSize;

    public function __construct(int $bufferSize = 100)
    {
        $this->bufferSize = $bufferSize;
    }

    public function addApiHit(
        string $spaceId,
        Carbon $timestamp,
        int $statusCode,
        string $ipAddress,
        ?float $timeTaken = null
    ): void {
        $hourKey = $this->getHourKey($spaceId, $timestamp);

        if (! isset($this->apiHitsBuffer[$hourKey])) {
            $this->apiHitsBuffer[$hourKey] = [
                'space_id' => $spaceId,
                'hour_timestamp' => $timestamp->startOfHour(),
                'hits' => 0,
                'ips' => [],
                'status_codes' => [],
                'time_taken_sum' => 0,
            ];
        }

        $this->apiHitsBuffer[$hourKey]['hits']++;
        $this->apiHitsBuffer[$hourKey]['ips'][$ipAddress] = true;

        $statusCodeKey = (string) $statusCode;
        $this->apiHitsBuffer[$hourKey]['status_codes'][$statusCodeKey] =
            ($this->apiHitsBuffer[$hourKey]['status_codes'][$statusCodeKey] ?? 0) + 1;

        if ($timeTaken !== null) {
            $this->apiHitsBuffer[$hourKey]['time_taken_sum'] += $timeTaken * 1000; // Convert to milliseconds
        }

        if (count($this->apiHitsBuffer) >= $this->bufferSize) {
            $this->flushApiHits();
        }
    }

    public function addTrafficUsage(
        string $spaceId,
        Carbon $timestamp,
        int $bytesSent,
        int $bytesReceived,
        bool $cacheHit
    ): void {
        $hourKey = $this->getHourKey($spaceId, $timestamp);

        if (! isset($this->trafficBuffer[$hourKey])) {
            $this->trafficBuffer[$hourKey] = [
                'space_id' => $spaceId,
                'hour_timestamp' => $timestamp->startOfHour(),
                'bytes_sent' => 0,
                'bytes_received' => 0,
                'request_count' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
            ];
        }

        $this->trafficBuffer[$hourKey]['bytes_sent'] += $bytesSent;
        $this->trafficBuffer[$hourKey]['bytes_received'] += $bytesReceived;
        $this->trafficBuffer[$hourKey]['request_count']++;

        if ($cacheHit) {
            $this->trafficBuffer[$hourKey]['cache_hits']++;
        } else {
            $this->trafficBuffer[$hourKey]['cache_misses']++;
        }

        if (count($this->trafficBuffer) >= $this->bufferSize) {
            $this->flushTraffic();
        }
    }

    public function addDownloadUsage(
        string $spaceId,
        Carbon $timestamp,
        int $bytesSent
    ): void {
        $hourKey = $this->getHourKey($spaceId, $timestamp);

        if (! isset($this->downloadBuffer[$hourKey])) {
            $this->downloadBuffer[$hourKey] = [
                'space_id' => $spaceId,
                'hour_timestamp' => $timestamp->startOfHour(),
                'bytes_sent' => 0,
                'request_count' => 0,
            ];
        }

        $this->downloadBuffer[$hourKey]['bytes_sent'] += $bytesSent;
        $this->downloadBuffer[$hourKey]['request_count']++;

        if (count($this->downloadBuffer) >= $this->bufferSize) {
            $this->flushDownloads();
        }
    }

    public function flush(): void
    {
        $this->flushApiHits();
        $this->flushTraffic();
        $this->flushDownloads();
    }

    private function flushApiHits(): void
    {
        if (empty($this->apiHitsBuffer)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->apiHitsBuffer as $data) {
                $record = ApiHitHourly::firstOrNew([
                    'space_id' => $data['space_id'],
                    'hour_timestamp' => $data['hour_timestamp'],
                ]);

                $record->hit_count = ($record->hit_count ?? 0) + $data['hits'];
                $record->unique_ips += count($data['ips']);

                $statusDistribution = $record->status_code_distribution ?? [];
                foreach ($data['status_codes'] as $code => $count) {
                    $statusDistribution[$code] = ($statusDistribution[$code] ?? 0) + $count;

                    $codeInt = (int) $code;
                    if ($codeInt >= 200 && $codeInt < 300) {
                        $record->success_count = ($record->success_count ?? 0) + $count;
                    } elseif ($codeInt >= 400) {
                        $record->error_count = ($record->error_count ?? 0) + $count;
                    }
                }
                $record->status_code_distribution = $statusDistribution;

                $record->time_taken_sum = ($record->time_taken_sum ?? 0) + $data['time_taken_sum'];

                $record->save();
            }
        });

        $this->apiHitsBuffer = [];
    }

    private function flushTraffic(): void
    {
        if (empty($this->trafficBuffer)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->trafficBuffer as $data) {
                $record = TrafficUsageHourly::firstOrNew([
                    'space_id' => $data['space_id'],
                    'hour_timestamp' => $data['hour_timestamp'],
                ]);

                $record->bytes_sent = ($record->bytes_sent ?? 0) + $data['bytes_sent'];
                $record->bytes_received = ($record->bytes_received ?? 0) + $data['bytes_received'];
                $record->request_count = ($record->request_count ?? 0) + $data['request_count'];
                $record->cache_hits = ($record->cache_hits ?? 0) + $data['cache_hits'];
                $record->cache_misses = ($record->cache_misses ?? 0) + $data['cache_misses'];

                $record->save();
            }
        });

        $this->trafficBuffer = [];
    }

    private function flushDownloads(): void
    {
        if (empty($this->downloadBuffer)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->downloadBuffer as $data) {
                $record = DownloadUsageHourly::firstOrNew([
                    'space_id' => $data['space_id'],
                    'hour_timestamp' => $data['hour_timestamp'],
                ]);

                $record->bytes_sent = ($record->bytes_sent ?? 0) + $data['bytes_sent'];
                $record->request_count = ($record->request_count ?? 0) + $data['request_count'];

                $record->save();
            }
        });

        $this->downloadBuffer = [];
    }

    private function getHourKey(string $spaceId, Carbon $timestamp): string
    {
        return $spaceId.'_'.$timestamp->format('Y-m-d_H');
    }
}
