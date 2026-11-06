<?php

namespace App\Services\Ai\Run;

use App\Models\Space\AiRun;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exclusive right to advance a run, stored on its row so that cache evictions
 * cannot hand a live run to a second worker. Taking a lease is one compare and
 * swap; taking over an expired one counts as an interruption of the run.
 *
 * The time to live must outlast what can silently pass between two renewals.
 * A provider stream can stay quiet while it sends tool arguments, so
 * `ai.runs.lease_seconds` exceeds the worker's hard job timeout: a lease cannot
 * expire while its worker is alive. The cost is that a killed worker's run
 * waits out the lease before it recovers.
 *
 * Time only decides when a lease may be taken over. Whether a worker may write
 * is decided by {@see fence()}, under the row lock, whatever its clock says.
 *
 * Timestamps assume worker clocks agree to well within the time to live.
 */
final class RunLease
{
    /** A renewal this recent proves ownership: nobody can take over before the lease expires. */
    private const RENEW_EVERY_SECONDS = 30;

    /**
     * The lease this process holds per run. A timed out job's failure handler
     * runs on a new job instance, and this is how it knows what is its own.
     *
     * @var array<string, string>
     */
    private static array $held = [];

    /** The lease whose fence is open in this process, for the timeout handler. */
    private static ?self $fencing = null;

    /** The connection's transaction level around the open fence. */
    private int $outerLevel = 0;

    private int $renewedAt;

    private bool $released = false;

    private function __construct(
        private readonly Connection $connection,
        private readonly string $runId,
        private readonly string $owner,
        private readonly int $seconds,
    ) {
        $this->renewedAt = now()->getTimestamp();
        self::$held[$runId] = $owner;
    }

    /** Take the lease, or null while a live worker holds it. */
    public static function acquire(AiRun $run, int $seconds): ?self
    {
        $connection = $run->getConnection();
        $owner = (string) Str::ulid();
        $now = now()->toDateTimeString();
        $claim = ['lease_owner' => $owner, 'lease_expires_at' => now()->addSeconds($seconds)->toDateTimeString(), 'updated_at' => $now];
        $query = fn () => $connection->table('ai_runs')->where('id', $run->id);

        try {
            $taken = $query()->whereNull('lease_owner')->update($claim) === 1
                || $query()->whereNotNull('lease_owner')->where('lease_expires_at', '<=', $now)
                    ->update([...$claim, 'interruptions' => $connection->raw('interruptions + 1')]) === 1;
        } catch (Throwable $e) {
            // Waited out a worker frozen inside its fence: it still owns the run.
            return self::isLockConflict($e) ? null : throw $e;
        }

        return $taken ? new self($connection, $run->id, $owner, $seconds) : null;
    }

    /**
     * This worker hit its hard timeout and is about to die: count the interruption
     * and free the run for the recovery sweep. A worker stalled past its lease
     * wakes up here too, so only its own lease goes, never a new owner's. The
     * alarm can fire inside a fence, on the same connection: that write goes
     * first, or this update would die with its transaction.
     */
    public static function forfeit(AiRun $run): void
    {
        $owner = self::$held[$run->id] ?? null;
        if ($owner === null) {
            return;
        }

        if (self::$fencing?->runId === $run->id) {
            self::$fencing->connection->rollBack(self::$fencing->outerLevel);
            self::$fencing = null;
        }

        unset(self::$held[$run->id]);
        $run->getConnection()->table('ai_runs')->where('id', $run->id)->where('lease_owner', $owner)->update([
            'lease_owner' => null,
            'lease_expires_at' => null,
            'interruptions' => $run->getConnection()->raw('interruptions + 1'),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Commit `$write` only if this worker still owns the run. The run's row
     * stays locked from the ownership check to the commit, and a takeover has to
     * update that row, so a takeover and a write cannot overlap: the write lands
     * whole before the takeover, or not at all after it. A worker frozen in here
     * still owns the run when it wakes, however late, and whoever wants the run
     * waits. Dying in here rolls everything back.
     *
     * This row is the first lock of `$write`, like every other transaction that
     * locks a run. `$write` must not call a provider or wait on the network.
     * Nested calls join the open fence.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws RunLeaseLost when another worker took over; nothing was written
     * @throws RunBusy on a deadlock or a lock timeout; nothing was written, and the next owner repeats from the checkpoint
     */
    public function fence(Closure $write): mixed
    {
        if (self::$fencing === $this) {
            return $write();
        }

        $this->outerLevel = $this->connection->transactionLevel();

        try {
            return $this->connection->transaction(function () use ($write) {
                $row = $this->connection->table('ai_runs')->where('id', $this->runId);

                if ((clone $row)->lockForUpdate()->value('lease_owner') !== $this->owner) {
                    unset(self::$held[$this->runId]);

                    throw new RunLeaseLost;
                }

                self::$fencing = $this;

                if (now()->getTimestamp() - $this->renewedAt >= self::RENEW_EVERY_SECONDS) {
                    $row->update(['lease_expires_at' => now()->addSeconds($this->seconds)->toDateTimeString(), 'updated_at' => now()->toDateTimeString()]);
                    $this->renewedAt = now()->getTimestamp();
                }

                return $write();
            });
        } catch (Throwable $e) {
            if (! self::isLockConflict($e)) {
                throw $e;
            }

            Log::warning('An AI run write lost a lock conflict and rolled back', ['run' => $this->runId, 'error' => $e->getMessage()]);

            throw new RunBusy('This AI run hit a lock conflict and continues from its last checkpoint.');
        } finally {
            self::$fencing = null;
        }
    }

    /**
     * Prove the lease is still ours and extend it. Cheap enough for every
     * stream delta: it touches the database at most every
     * {@see RENEW_EVERY_SECONDS}. It stops a stale worker early. It guards no
     * write: that is {@see fence()}.
     *
     * @throws RunLeaseLost
     */
    public function heartbeat(): void
    {
        if ($this->released || now()->getTimestamp() - $this->renewedAt < self::RENEW_EVERY_SECONDS) {
            return;
        }

        $this->fence(static fn () => null);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        unset(self::$held[$this->runId]);
        $this->connection->table('ai_runs')->where('id', $this->runId)->where('lease_owner', $this->owner)->update([
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private static function isLockConflict(Throwable $e): bool
    {
        return app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e);
    }
}
