# W5 runtime verification evidence

Branch `t3/verify-runtime-recovery`, built on `f2e21923`. Engineering checks of the long-horizon runtime on real MySQL with real processes and real signals. One slice of the brief. It does not complete it, and it says nothing about provider behavior or editorial quality.

## Result

Two failures reproduced and are fixed in this branch. Every stated guarantee then held on two full passes, with one documented limit (`s3b`).

| Guarantee | Scenario | Result |
| --- | --- | --- |
| One owner through a quiet slow provider call | `s1`, `s1c` | Held |
| Competing workers do not both call the provider | `s1`, `s1b`, `s2` | Held |
| A killed worker recovers from its checkpoint, once, after the lease | `s2` | Held |
| A stale worker cannot checkpoint or write after a new owner | `s3`, `s3c`, `s4` | Held after fix 1. Limit in `s3b` |
| Transient retry `not_before` across processes and redelivery, no item attempt | `s7` | Held |
| Reconciled cost deltas are idempotent under row locks | `s8` | Held |
| Crash after a proposal write, before its checkpoint | `s5` | Held |
| A lost image buffer never counts as delivered | `s6` | Held |
| Crash between event row and counter | `s10` | Held after fix 2 |
| Discovery scan recovers from a lost job and a killed slice | `s9` | Held |
| Migrations 000022, 000024, 000026 and document soft delete, up and down | `m1` | Held |

## Failures reproduced and fixed

**1. A stalled worker's timeout removed the new owner's lease.** `RunLease::forfeit()` cleared any owner. A worker stalled past its lease, then resumed with its alarm due, freed the run from the worker that had taken it over.

Before the fix, on MySQL: lease owner `01M6EDD1…` became null, `interruptions` went to 2, and a third worker started while the new owner was still in its provider call. `s4` showed 4 calls started where 2 were expected.

`forfeit()` now releases only a lease this process holds, tracked in `RunLease::$held`. It has to be per process, because the framework runs `failed()` on a new unserialized job instance. A worker holding no lease does nothing on timeout, and no interruption is counted.

**2. A crash between an event row and `event_seq` failed the run for good.** `emit()` stored the event and then updated the counter as two statements. Killed between them, the next owner reused the sequence number.

Before the fix: `event_seq` 2, highest stored `seq` 3, `SQLSTATE[23000] 1062 Duplicate entry` on `ai_run_events_run_id_seq_unique`, run `failed` with `background_failed`, operation left without its proposal. The window is one statement wide, but the cost is a lost run. The two statements are now one transaction.

Two focused tests cover these in `tests/Feature/Ai/AiRunRecoveryTest.php`. The existing test `a_run_that_keeps_losing_its_worker…` wrote `lease_owner` by hand and now takes a real lease, since a timeout only frees a lease its own process holds.

## Environment

- MySQL 26.10.0 (Homebrew), InnoDB, `REPEATABLE-READ`, `STRICT_TRANS_TABLES`, `ONLY_FULL_GROUP_BY`.
- A private throwaway `mysqld`, PID 7663, data directory inside this checkout, `--skip-networking`, unix socket only, `--no-defaults`. `innodb_flush_log_at_trx_commit=2`, since workers died and the server did not.
- Management and space on separate databases, joined through a real encrypted `SpaceConnection`, so `SpaceModelResolver` ran its production path. `APP_ENV=w5`, fresh `APP_KEY`, no `.env`, and `env -i` for every process.
- Real `queue:work` daemon loops on the `database` queue, with pcntl timeouts armed, except where noted. `ai:recover-runs` was the real command. Cache locks used the `file` store across processes.
- PHP 8.5.12. `vendor/` is an APFS clone of the parent's.
- Provider: a fake driver bound in each child, logging call start and end to a shared file. No network and no paid call.
- Applied temporarily and reverted afterwards: the parent's uncommitted patches to `RunRecovery.php`, `KnowledgeImpactScanner.php` and `BedrockDriver.php`. `s9` depends on them, so the parent must keep them. They are not in this branch.

The harness was scratch code in `.w5/` and is deleted: `lib.php` (driver and crash hooks), `child.php` (process modes), `run.php` (orchestrator).

## What ran

Two full passes of all scenarios, with 189 child processes in each. Each ended at 122 passing checks and the single `s3b` failure. `s8` and `s1c` were rerun after a harness bug in `s8` (below), and `s8` passed twice more.

| Scenario | Processes and signal | Evidence |
| --- | --- | --- |
| `s1` | 6 workers on 6 jobs for one run | 1 provider call, 5 exit code 0. 2 parallel sweeps and a duplicate job at clock +300s and +899s: 0 recovered, no call, same owner, 0 interruptions. 3 calls overall, never overlapping |
| `s1b` | 12 processes at a microsecond barrier, 5 rounds | Exactly 1 winner each round: 2 rounds free, 3 expired. Each expired round adds exactly 1 interruption |
| `s1c` | 1 worker, clock +31s during a call | The renewal updates the row and the owner keeps going. Lease moved from 15:40:58 to 15:41:29. MySQL reports changed rows here, and the update does change them |
| `s2` | External SIGKILL 0.33s into the second, quiet call | Checkpoint has the first proposal and nothing of the killed call. The dead lease blocks the sweep. Redelivery fails the job with `MaxAttemptsExceededException` and leaves the run alone. After expiry, 2 sweeps and 4 workers: calls by message count `[2,4,4,6]`, identical request hash for the repeat, no overlap, 1 interruption |
| `s3` | Stale worker SIGSTOPped in a call, resumed after the new owner finished | Gets its response, then exits 0 and drops it. Run row, transcript, events, operation and proposals identical before and after |
| `s3c` | SIGSTOP inside a tool, just before the proposal row, real 3s alarm, real 5s lease | On SIGCONT the alarm kills the worker before the write. 1 proposal, finished run untouched |
| `s4` | SIGSTOP in a call, real 3s alarm, real expiry after 5.5s, new owner mid-call, then SIGCONT | Worker dies by SIGKILL from the queue's timeout handler. New owner's lease unchanged, third worker makes no call, run completes. This is fix 1 |
| `s4b` | Real 2s alarm in a quiet call | Own lease freed, 1 interruption, attempts 0, sweep finishes the run |
| `s5` | Self SIGKILL in `AiRun::updating`, after the proposal commit and before the checkpoint | 1 proposal row, tool call still pending, entry already `proposed`. Replay runs the tool with no repeat provider call (`[2,4,6]`), replaces the row, 1 proposal per entry, spend correct |
| `s6a` | Self SIGKILL with the image only in worker memory | Tool result says `attached`, `delivered()` is empty. The first call after recovery carries no image. A fake model claiming to have seen it is refused with "has not reached you". After a real inspection the evidence SHA-256 matches the bytes the provider received |
| `s6b` | Self SIGKILL in the provider call carrying the image | Image is in the checkpoint, `delivered()` still empty. Replay resends it, the answer counts, evidence is correct |
| `s7` | 2 daemons, 30 sweeps with zero grace, 30 early duplicate jobs, real backoff 2s, 4s, 8s | 1 call plus 3 retries, gaps 1.87s, 3.95s, 7.95s (2.11s, 3.94s, 8.06s on the first run). Ends `provider_unavailable`, operation `batch_failed`, entry `pending` with 0 attempts |
| `s7b` | Delayed job, then the job deleted | `available_at` matches `not_before` to the second. A sweep and an early job inside the backoff do nothing. A sweep past `not_before` dispatches once. Completes with `provider_retries` 1 |
| `s8` | 11 processes, 15 rounds each, real `ReconcileAiRunCostJob::handle()` and `syncSpend()` over 5 runs, figures 0.25, 0.125, 1.5 | `spent_usd` 1.25, 0.625, 7.5, equal to the sum of run costs, including the move down. `counted_cost_usd` equals `cost_usd` on every run. 0 process errors |
| `s8b` | Self SIGKILL inside the settlement transaction, then 2 sweeps, 2 `runFinished`, 2 reconciles | Rolled back whole. Spend reaches 0.777 through reconcile while the dead worker's cache lock blocks settlement. After the lock expires (clock +130s), settled once: tokens 30, spend 0.777, operation completed, no attempts used |
| `s9` | Kills after a slice commit and inside a slice, 4 parallel sweeps, 3 daemons with 3 duplicate jobs | 4 sweeps dispatch 1 job. Rollback keeps the cursor. The recovered scan equals a clean scan: 125 candidates, 328 rows scanned once. Stored snapshot fingerprint equals the live one |
| `s10` | Self SIGKILL in `AiRunEvent::created` | Now rolled back: `event_seq` 2, highest `seq` 2. Run completes, sequence has no gaps. This is fix 2 |
| `m1` | Fresh throwaway databases with rows present | All space and management migrations up. Scan delete cascades to candidates. 000026 down, then 000024 and 000022 down, then up again. Document soft delete down and up |

Harness bug worth recording: `s8` first keyed its cost figures by float, which PHP truncates, so it only tested 0 and 1. I fixed it and reran. The figures above are from the rerun.

## Where time was simulated

- `s1` competitors and the late `s8b` sweeps ran with a frozen clock offset in that child. The call itself was about 2s of real time, not 900s.
- `s2`, `s3`, `s5`, `s6` and `s10` expired the lease by updating the test row. `s3` and `s3b` moved the stale child's clock forward 1000s on resume, because a stale worker only rechecks its lease once 30s have passed on its own clock.
- `s3c` and `s4` used real expiry and a real alarm, scaled to 3s and 5s. `s7` used real delays.
- `s4` and `s3c` changed `timeout` in the payload of test job rows. Production keeps 900s against 960s.

## Limits that remain

- **`s3b`, stale write without an alarm.** A worker that wakes past its lease inside a tool, at the moment before its proposal row, still writes it. Its next checkpoint then stops it. Result: a second identical pending row for the same path, since the arguments come from the same checkpointed transcript. Acceptance of such a pair was not exercised. This needs a stall of at least 960s inside one write on a worker with no pcntl alarm, or a clock jump. With the alarm armed, `s3c` shows the alarm firing first. I left it, because closing it means fencing every tool write with a row lock, which is too much for this risk. The recommendation is to note it for hosts without pcntl.
- **Spend can undercount interrupted calls.** A killed or timed out call has no recorded usage. `s2` shows the repeated call counted once and the killed call counted zero times, and the fake driver charged nothing for it. There is no hard zero-overspend promise. Budget caps act on recorded spend.
- **Recovery latency.** A SIGKILLed run waits out 960s plus up to 2 minutes for the sweep. A worker killed during settlement holds the 120s operation cache lock, and spend from reconcile stays correct meanwhile. An evicted cache lock is still W1's limit.
- **After a lost image buffer** the stored tool result keeps saying `attached` and the model is not told. Evidence stays honest. The model pays for extra turns: `s6a` used 5 calls where a clean run uses 3.
- A race of 2 sweeps can dispatch 2 jobs for one run. The lease excludes them, so this costs a job and no provider call.

## Not exercised

- Real providers, real streams, or a timeout interrupting real cURL. The parent's Bedrock `@retries` line was applied but no SDK call ran.
- A MySQL server crash or failover, a read replica with lag (`read`/`write` config pointed at one server), or production volumes.
- Redis or SQS queues and Redis cache locks. Only the `database` queue and `file` cache ran.
- Semantic review recovery with pending subjects. `SemanticReviews::sweep()` ran its JSON path queries on MySQL with no pending rows, and these operations carried no policy reviews.
- `show engine innodb status` was not read successfully, so deadlocks are bounded only by no process reporting one, without retries. `s8` is the most contended path and had 0 errors.
- SIGTERM of a worker mid-job, as ECS sends on deploy. It should reduce to the SIGKILL path once the stop timeout passes.

## Commands

```
mysqld --no-defaults --initialize-insecure --datadir=<checkout>/.w5/data
mysqld --no-defaults --datadir=… --socket=… --skip-networking --mysqlx=OFF --innodb-flush-log-at-trx-commit=2
php artisan migrate --force                                  (management, private database only)
php .w5/run.php setup
php .w5/run.php m1                                           ALL PASS
php .w5/run.php s1 s1b s2 s3 s3b s3c s4 s4b s5 s6 s7 s8 s9 s10   122 PASS, 1 FAIL (s3b), twice
php .w5/run.php s1c s8 s8                                    ALL PASS
vendor/bin/phpunit tests/Feature/Ai                          OK, 308 tests, 2355 assertions, 1 skipped
vendor/bin/phpunit tests/Unit/Services/Ai                    OK, 71 tests, 192 assertions
vendor/bin/pint --test <changed PHP>                         passed
```

## Cleanup

`mysqld` 7663 stopped with `mysqladmin shutdown` and confirmed gone. No child process was left, checked with `pgrep`. `.w5/`, with its data directory, logs and harness, is deleted. No process was started outside this checkout, and no existing process or real application database was touched.
