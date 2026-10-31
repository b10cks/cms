<?php

namespace App\Services\Provider;

use App\Models\Management\SpaceConnection;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Instance health for the provider console: queue depth, recent failed jobs
 * and space database connectivity. The connectivity check opens a sample of
 * space databases and is cached, so the page never connects to every space.
 */
class InstanceHealth
{
    public const SPACE_DB_SAMPLE = 20;

    private const SPACE_DB_CACHE_KEY = 'provider:health:space-dbs';

    private const SPACE_DB_CACHE_SECONDS = 600;

    public function __construct(private readonly DatabaseConnectionService $connections) {}

    /** @return array<string, mixed> */
    public function report(bool $recheckSpaces = false): array
    {
        if ($recheckSpaces) {
            Cache::forget(self::SPACE_DB_CACHE_KEY);
        }

        return [
            'queue_connection' => config('queue.default'),
            'queues' => $this->queues(),
            'failed_jobs_24h' => $this->failedJobs(),
            'space_databases' => Cache::remember(
                self::SPACE_DB_CACHE_KEY,
                self::SPACE_DB_CACHE_SECONDS,
                fn () => $this->checkSpaceDatabases(),
            ),
        ];
    }

    /** @return array<int, array{name: string, size: int|null}> */
    private function queues(): array
    {
        return collect(config('queue.monitored', ['default']))
            ->map(function (string $name) {
                try {
                    $size = Queue::connection()->size($name);
                } catch (Throwable) {
                    $size = null;
                }

                return ['name' => $name, 'size' => $size];
            })
            ->values()
            ->all();
    }

    private function failedJobs(): ?int
    {
        try {
            return DB::connection(config('queue.failed.database'))
                ->table(config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function checkSpaceDatabases(): array
    {
        $sample = SpaceConnection::query()
            ->where('is_default', true)
            ->whereHas('space')
            ->with('space:id,name')
            ->inRandomOrder()
            ->limit(self::SPACE_DB_SAMPLE)
            ->get();

        $failures = [];

        foreach ($sample as $connection) {
            if (! $this->connections->testConnection($connection)) {
                $failures[] = ['space_id' => $connection->space_id, 'space_name' => $connection->space?->name];
            }

            $this->connections->clearConnection($connection);
        }

        return [
            'total' => SpaceConnection::query()->where('is_default', true)->count(),
            'checked' => $sample->count(),
            'failed' => count($failures),
            'failures' => $failures,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
