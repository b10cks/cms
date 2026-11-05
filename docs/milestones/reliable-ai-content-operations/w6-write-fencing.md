# W6 write fencing evidence

Branch `t3/fence-stale-worker-writes`, built on `1baaed8f`. Closes `s3b` from [W5](w5-runtime-verification.md). One slice of the brief. It says nothing about provider behavior or editorial quality.

## Result

A worker that lost its lease can no longer write, whenever it wakes up and whatever its clock says. This holds without pcntl and needs no schema change.

## The gap, reproduced before the fix

On `1baaed8f`, real MySQL, real processes. Worker A was SIGSTOPped inside the proposal write with a real 3s lease. Worker B took over after real expiry and finished. A then got SIGCONT.

| Check | Before |
| --- | --- |
| Pending proposals for the one entry | 2, same target and path |
| A's end | `1062 Duplicate entry` on `ai_run_events_run_id_seq_unique` |
| Stored events against `event_seq` | 9 against 3 |
| B blocked while A sat inside its write | no, 0 lock waits |

With a frozen clock (`stale_clock`, standing in for a clock jump) a stale worker past its response also crashed on `1062`, since its throttled heartbeat never checked the row.

## The fix

**`RunLease::fence()` is the write boundary.** It opens one transaction, takes `SELECT lease_owner … FOR UPDATE` on the run's row, compares the exact owner, runs the write, and commits.

- A takeover has to update that row, so a takeover and a fenced write cannot overlap. The write lands whole before the takeover, or not at all after it.
- Ownership is decided under the lock. No time is involved, so a stall or clock jump of any size is safe. An expired lease nobody took yet still belongs to its worker.
- Lost ownership throws `RunLeaseLost` and rolls back. The job drops out as before.
- `heartbeat()` is now a throttled empty fence. It stops a stale worker early and guards nothing.

**What is inside the fence.** All writes of the loop, through `AiRunService::fenced()`:

| Boundary | One commit |
| --- | --- |
| A tool | its writes, its ledger accounting, its result in the transcript |
| `complete()` | selection proposal, translations, result, state, thread message, final event |
| `stop()`, `fail()`, `cancel()` | error, state, checkpoint, event |
| `emit()` | event row and `event_seq`, keeping W5's fix 2 |
| Every checkpoint and other save | the run row |

Domain tools and `ChangesetRecorder` did not change. A new tool that writes is fenced without asking for it.

**The one exception.** `AiRunService::READ_BEFORE_FENCE` lists `inspect_assets`, which reads files, maybe from S3, and writes nothing. It runs with no lock. Its outcomes and result are stored under the fence. A worker stalled in that read writes nothing when it wakes.

**No network inside the lock.**

- I read every tool, the recorder and `SemanticSubjects`. None calls a provider. Only `inspect_assets` reads storage.
- `AiThreadChanged` and `AiRunEventsAvailable` are `ShouldBroadcastNow`, a synchronous call to Reverb, and the recorder sends one mid-write. Both now implement `ShouldDispatchAfterCommit`, so they send after the commit and not at all on a rollback.
- `ReconcileAiRunCostJob` dispatches after the commit.

**Three cases the lock itself created.**

- *Waiting out a frozen worker.* `acquire()` would throw `1205` after `innodb_lock_wait_timeout`, which would have failed the run. A lock conflict there now means busy.
- *Deadlock or lock timeout inside a fence.* The whole transaction rolls back, a warning is logged, and the worker stops with `RunBusy`. There is no retry in place, because a retry would repeat tool buffers and transcript appends. The next owner replays from the checkpoint with no provider call.
- *A real alarm inside a fence.* The timeout handler runs on the same connection, so `forfeit()` would have written into the transaction that dies with the process. It rolls the fence back first, so the lease is freed and the interruption counted.

**Failing from outside the loop.** `fail()` from a failed job or an aborted stream now takes the lease first. While another worker holds it, nothing happens. `advance()` also skips `afterRun` after an exception, because the finished state on that model may have rolled back. The sweep covers it.

## Lock order

The run's row comes first, then whatever the write touches: changeset, items, ledger, thread. `syncSpend`, `updateMemory` and `countOnOperation` already lock the run first. Acceptance locks items and a settlement locks items and the operation without touching the run row, so those can only conflict on items. If the fence loses such a conflict, it takes the rollback path above.

## MySQL evidence

MySQL 26.10.0, InnoDB, `REPEATABLE-READ`, `innodb_lock_wait_timeout` 50. Private `mysqld` PID 11421, `--no-defaults --skip-networking --mysqlx=OFF`, socket and data inside this checkout. Separate `w6_mgmt` and `w6_space` databases, joined through a real encrypted `SpaceConnection`, so `SpaceModelResolver` ran its production path. PHP 8.5.12, `env -i` for every process, `APP_ENV=w6`, fresh `APP_KEY`, `file` cache, `database` queue, null broadcaster. Fake provider only, no network and no paid call.

Final pass on the committed code: **192 checks, 0 failed, 147 child processes.**

Each scenario ends with the same checks: exactly one pending row per entry, no target and path twice, run completed, events gapless and equal to `event_seq`, each tool call once with one result, ledger `proposed`, operation completed, spend equal to run cost, thread message at most once.

| Scenario | Processes and signal | Outcome |
| --- | --- | --- |
| `s3b` | A SIGSTOPped inside the proposal write, real 3s expiry, then B | B blocked with 1 InnoDB lock wait in `performance_schema.data_lock_waits` and 1 provider call so far. After SIGCONT, A commits as the owner it still was, then ends with `RunLeaseLost` at its next fence, and B finishes. 1 proposal |
| `s3b_wait` | A frozen past the real lock timeout, real `RunAgentJob` and a sweep | Job gave up after 50.5s and exited clean, not failed. The sweep stayed alive. Run still `running`. After SIGCONT, A finishes. 1 proposal |
| `stale_clock` | A SIGSTOPped in a provider call, its clock frozen, real expiry, B finishes | A ends with `RunLeaseLost` at its first write. Run row hash and event count identical before and after |
| `kill_item` | Self SIGKILL after the proposal insert | Proposal gone, tool call pending. Takeover refused before expiry. After expiry the tool repeats with no provider call for that step |
| `kill_ckpt` | Self SIGKILL in the tool's checkpoint | Proposal and ledger rolled back with it. W5's `s5` state, a proposal with no checkpoint, no longer exists for fenced tools |
| `ext_kill` | External SIGKILL of the stopped lock holder while B waits | B moved 68ms after the kill and took over |
| `kill_event` | Self SIGKILL in `AiRunEvent::created` | W5's fix 2 holds: no event above the counter |
| `alarm_fence` | Real `queue:work` daemon, real 2s alarm inside the write | Worker dies by SIGKILL. Lease freed, 1 interruption, nothing written. B completes |
| `alarm_stale` | Real daemon, SIGSTOP in a call, real 3s alarm, real 5s expiry, B mid-call, SIGCONT | W5's fix 1 holds: B's lease unchanged, only the takeover counted |
| `deadlock` | Real InnoDB deadlock against a process holding the changeset row and wanting the run row | `lock_deadlocks` rose by 1. The fence was the victim with `1213`, stopped as `RunBusy`, 1 warning logged. Second proposal rolled back, first kept, lease released. B replays. 2 proposals |
| `sweep_frozen` | Sweep during a stopped fence | Waits on the row, then continues |
| `chaos` | 4 rounds, 4 competing `RunAgentJob` workers at a time, 1s lease, random SIGKILLs | 96 workers, 15 SIGKILLs, 11 interruptions, 22 provider calls, 20 write attempts for 16 proposals. All checks hold each round |

**The lock itself is what holds.** With the owner check kept and only `lockForUpdate` removed, `s3b` fails: B crashes on `1062` and the run stays `running`. SQLite cannot show this.

### Where time was simulated

- Lease expiry in every scenario here was real, scaled from 960s to 1s, 3s or 5s through `AI_RUN_LEASE_SECONDS`. No test row was edited to expire a lease. The 50s lock wait was real.
- `stale_clock` freezes A's clock with `Carbon::setTestNow`, to stand in for a clock jump.
- `alarm_fence` and `alarm_stale` changed `timeout` in the payload of test job rows.
- The fake provider and the writes are short. `chaos` adds 100ms to 300ms sleeps so kills land mid-work.

## Tests

Seven tests were added, six in `tests/Feature/Ai/AiRunRecoveryTest.php` and one in `AiAssetOperationsTest.php`. On old source with the new tests, all six recovery tests fail. The asset test passes there, as it should, since nothing was fenced then.

Mutations, each caught by the matching test: fence skips the owner check (2 tests), `inspect_assets` moved inside the fence (1), broadcasts inside the commit (1).

```
vendor/bin/phpunit tests/Feature/Ai                                  OK, 327 tests, 2567 assertions
vendor/bin/phpunit tests/Unit/Services/Ai                            OK, 71 tests, 192 assertions
vendor/bin/phpunit tests/Feature/Mgmt/AssetClassificationTest.php    OK, 28 tests, 135 assertions
vendor/bin/phpunit tests/Feature/Mgmt/DataEntryTranslationStreamTest.php  OK, 2 tests, 5 assertions
vendor/bin/pint <changed PHP>                                        passed
php .w6/run.php                                                      192 passed, 0 failed
```

The parent's patches to `RunRecovery.php`, `KnowledgeImpactScanner.php` and `BedrockDriver.php` were not applied, since nothing here needs them. They do not overlap these changes.

## Limits that remain

- **A frozen worker inside a fence stops that run until it resumes or dies.** Safety holds and liveness does not. A stop that freezes the process also holds off its alarm, so nothing frees the row until the process is killed or MySQL drops the idle session (`wait_timeout`, default 8h). Meanwhile each worker and each sweep waits 50s for that run and drops out, and from reading the code, an editor's cancel waits 50s and then returns an error. A process that dies frees the row at once, as `ext_kill` shows. Only a stop inside a short database write can do this, which is far narrower than before.
- **After losing a lock conflict, a run waits for the sweep:** 300s grace plus up to 2 minutes. The job does not re-dispatch itself, because `RunAgentJob` was out of scope. No interruption is counted. It only happened when forced.
- **A stale worker's provider call stays uncounted.** Its response is dropped with its usage, so spend can undercount as W1 and W2 document. The new owner's cost is exact.
- **A stale inline stream** can still send text to its own client until its next fence. Nothing is stored.
- **`answer()` holds no lease.** Its state claim guards it, and the run is paused then. The first `cancel_requested` save is unfenced too, and only writes that flag.
- **Clocks still decide when a takeover may happen.** Skew can cost a repeated provider call. It cannot cost a duplicate write.
- **A tool's `ToolError` commits what it wrote before the error,** as it did before.

## Not exercised

- Real Reverb. After-commit dispatch is checked by a test on SQLite with a listener, and the MySQL harness used the null broadcaster.
- Real S3 reads in `inspect_assets`, and a stall inside that tool on MySQL. A test shows no transaction is open while it reads.
- Lock hold time at production tool sizes, and the extra locked read per event at volume. Both are one primary key read, not measured under load.
- A MySQL failover or a lost connection inside a fence. That reaches the ordinary job failure path.
- A lowered `wait_timeout` as a bound on the frozen case. Provider calls can outlast it, and reconnects outside a transaction were not tested for this.
- Postgres spaces, Redis or SQS queues and Redis cache.

## Cleanup

Harness in `.w6/` (`lib.php`, `h.php`, `run.php`), with its data, logs and keys, is deleted. `mysqld` 11421 was stopped with `mysqladmin shutdown` and confirmed gone. `pgrep` found no harness process left. The system `mysqld` (PID 1363) and all real databases were not touched.
