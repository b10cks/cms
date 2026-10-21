<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * State of one bulk publish or unpublish run, kept in the cache so the SPA can
 * poll it while BulkPublishContentJob works through the ids. Runs are
 * short-lived and their outcome is in the audit log, so there is no table.
 *
 * One run per space at a time: the controller takes lock() before dispatching
 * and the job releases it when done.
 *
 * @phpstan-type Failure array{id: string, name: string|null, message: string}
 */
final class ContentBulkPublishRun
{
    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /** How long a finished run stays readable. */
    private const TTL_SECONDS = 86400;

    /**
     * Covers queue wait plus the job's 900 s timeout. A worker that dies
     * without running failed() blocks the space for at most this long.
     */
    public const LOCK_SECONDS = 1800;

    /**
     * @param  array{id: string, action: string, state: string, progress: int, total: int, succeeded: list<string>, failed: list<Failure>, index_failed: list<Failure>, error: string|null}  $attributes
     */
    private function __construct(
        private readonly string $spaceId,
        private array $attributes,
    ) {}

    public static function lock(Space $space): Lock
    {
        return Cache::lock(self::lockKey($space->id), self::LOCK_SECONDS);
    }

    /**
     * Remembers which run holds the lock, so a rejected second request can
     * tell the client which run to follow.
     */
    public static function markActive(string $spaceId, string $runId): void
    {
        Cache::put(self::activeKey($spaceId), $runId, self::LOCK_SECONDS);
    }

    public static function activeRunId(string $spaceId): ?string
    {
        return Cache::get(self::activeKey($spaceId));
    }

    public static function releaseLock(string $spaceId, string $owner): void
    {
        Cache::forget(self::activeKey($spaceId));
        Cache::restoreLock(self::lockKey($spaceId), $owner)->release();
    }

    /**
     * @param  'publish'|'unpublish'  $action
     */
    public static function start(Space $space, string $action, int $total): self
    {
        $run = new self($space->id, [
            'id' => strtolower((string) Str::ulid()),
            'action' => $action,
            'state' => self::STATE_QUEUED,
            'progress' => 0,
            'total' => $total,
            'succeeded' => [],
            'failed' => [],
            'index_failed' => [],
            'error' => null,
        ]);
        $run->save();

        return $run;
    }

    public static function find(string $spaceId, string $id): ?self
    {
        $attributes = Cache::get(self::key($spaceId, $id));

        return is_array($attributes) ? new self($spaceId, $attributes) : null;
    }

    public function id(): string
    {
        return $this->attributes['id'];
    }

    public function markAsRunning(): void
    {
        $this->fill(['state' => self::STATE_RUNNING]);
    }

    /**
     * @param  list<string>  $succeeded
     * @param  list<Failure>  $failed
     * @param  list<Failure>  $indexFailed
     */
    public function updateProgress(int $progress, array $succeeded, array $failed, array $indexFailed): void
    {
        $this->fill([
            'progress' => min(99, max(0, $progress)),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'index_failed' => $indexFailed,
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->fill(['state' => self::STATE_COMPLETED, 'progress' => 100]);
    }

    public function markAsFailed(string $error): void
    {
        $this->fill(['state' => self::STATE_FAILED, 'error' => mb_substr($error, 0, 255)]);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    private function fill(array $attributes): void
    {
        $this->attributes = $attributes + $this->attributes;
        $this->save();
    }

    private function save(): void
    {
        Cache::put(self::key($this->spaceId, $this->attributes['id']), $this->attributes, self::TTL_SECONDS);
    }

    private static function key(string $spaceId, string $id): string
    {
        return "content-bulk-publish:{$spaceId}:run:{$id}";
    }

    private static function activeKey(string $spaceId): string
    {
        return "content-bulk-publish:{$spaceId}:active";
    }

    private static function lockKey(string $spaceId): string
    {
        return "content-bulk-publish:{$spaceId}:lock";
    }
}
