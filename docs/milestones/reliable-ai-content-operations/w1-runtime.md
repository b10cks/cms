# W1 runtime evidence

Branch `t3/ai-run-recovery-cost`, built on `5cf074f0`. Foundation slice only. It does not complete the brief.

## Risk reproduced before the fix

On `5cf074f0`, a job holding the 300s cache lock was suspended in a provider call for 301s and a second `RunAgentJob` ran. The second worker made its own provider call for the same step and completed the run. The first worker then crashed on a duplicate event sequence number when its response arrived, after both calls were paid for. This was a temporary test on a scratch checkout. The other risks came from reading the code:

- Claiming a batch was not atomic. A crash between the item update and the `batch_count` save left entries `running` for good.
- A lost dispatch left an operation `running` until an editor clicked resume.
- `ReconcileAiRunCostJob` changed only the run, so operation spend drifted.
- A stale redelivery of a dead worker's job failed the run about an hour later.

## Behavior now

**Ownership.** `RunLease` is a compare-and-swap lease on the `ai_runs` row. It replaces the cache lock, because the production cache node evicts keys.

- Background and no-deadline runs hold it for `ai.runs.lease_seconds` (960s). That exceeds the 900s `RunAgentJob` timeout, so a quiet stream cannot make a live worker look dead.
- Inline streams hold it for `ai.runs.inline_lease_seconds` (300s), the same as the old lock.
- Stream deltas and checkpoints renew it, at most every 30s.
- A worker that lost its lease stops with `RunLeaseLost`, which extends `RunBusy`, and drops its response without writing.
- Handover releases the lease before dispatching, so the old 2s delay is gone. A job that finds the run busy now drops out instead of re-dispatching every 5s.

**Provider lifetime.** The slice deadline is checked only between provider calls, so a response is never cut by the slice. A call started just in time has 600s (900s timeout minus 300s slice).

- A longer call is killed by the worker timeout. `RunAgentJob::handleFailure` frees the lease, counts one interruption and leaves the run running.
- The call then repeats from the last checkpoint. No partial response is saved and no checkpointed call is skipped.
- Nothing is sent to the provider to end such a call.

**Recovery.** `ai:recover-runs` runs every two minutes on one server and calls `RunRecovery` per space.

- Each `running` operation goes through the normal `advance()`. That settles an unsettled finished run, launches a claimed batch through its idempotency key, or starts the next batch.
- Background and operation runs with no live lease and no activity for `recovery_grace_seconds` (300s) get a new job.
- A run with `cancel_requested`, a pause request, or an operation that is no longer running it gets cancelled and settled, with no provider call. The entries cost no attempt.
- The first step after taking a run checks cancellation and access, before any pending tool call repeats.
- After `max_interruptions` (3) lost workers the run fails with `background_failed`. The operation pauses with `batch_failed` and proposals stay.
- Paused, cancelled, draft and budget-stopped operations are never advanced. Frozen scope and approvals are never changed.
- Non-operation background runs recover too. They lost the 5s busy retry, and the sweep query covers them with no extra code.

**Spend.** `ai_runs.counted_cost_usd` holds the run's share of `spent_usd`. `AiOperations::syncSpend()` moves only the difference, in a transaction under a row lock. Settlement and `ReconcileAiRunCostJob` both call it, in either order and any number of times.

- `ai_runs.cost_confirmed_at` separates confirmed from estimated cost.
- Planning runs are not counted.
- A lower cost never changes operation state. A higher cost pauses the next batch through the existing budget check. `max_usd` changes only through `extendBudget`.

Settling and claiming a batch are now database transactions.

## Interfaces for other work

- `AiRun::$lease` holds the in-process lease and is never persisted. `AiRun::isLeased()` reports a live holder.
- `AiOperations::mayContinue(AiRun)` and `AiOperations::syncSpend(AiRun)` are public.
- New `ai_runs` columns: `lease_owner`, `lease_expires_at`, `interruptions`, `counted_cost_usd`, `cost_confirmed_at`. `AiRunResource` does not expose them yet; receipts can read `cost_confirmed_at` in wave 4.
- Tool image semantics are unchanged: `InspectionImages` calls stay where they were.

## Deployment

- Run `spaces:repair-databases` before this code takes traffic. Migration `0000_00_00_000022` adds required columns and hosted deploys skip space migrations.
- The scheduler must run. Without it nothing recovers, and stale redeliveries no longer fail the run.
- New optional env: `AI_RUN_LEASE_SECONDS`, `AI_RUN_INLINE_LEASE_SECONDS`, `AI_RUN_RECOVERY_GRACE_SECONDS`, `AI_RUN_MAX_INTERRUPTIONS`.
- Keep `AI_RUN_LEASE_SECONDS` above the `RunAgentJob` timeout. A test pins the defaults.

## Assumptions

- Worker clocks agree to well within the lease time. Timestamps have one second resolution.
- The queue worker enforces the job timeout. Without `pcntl` (shared hosts) a stuck process is not killed, so it keeps its lease only while it renews.
- A job waiting in a long queue can gain a duplicate after the grace period. The duplicate finds the run busy or finished, or runs a slice under the lease. Exclusion holds either way.
- The sweep opens each space's database every two minutes. That has not been measured at production space count.

## Limits

- A SIGKILLed worker's run waits up to 960s for its lease, plus the sweep interval.
- A call killed by a timeout or a crash has no recorded usage. Its spend and generation ID are lost, so operation spend can undercount by those calls. With `max_interruptions` at 3, one run can lose at most 3.
- `provider_error` still pauses the operation with `batch_failed` and needs a resume. Only lost workers recover automatically. The brief asks for recovery from transient failures, so this needs a decision, see the report.
- A run recovered past `max_seconds` completes as partial, and its entries lose one attempt before they retry in a new batch.
- The operation lock remains a 120s cache lock around short steps. An evicted lock could allow two concurrent steps there.
- Text deltas streamed before a crash stay in the stored events, so followers can see repeated text after a replay.

## Checks

Commands ran from this checkout with its own `vendor/`.

```
vendor/bin/phpunit tests/Feature/Ai/AiRunRecoveryTest.php tests/Feature/Ai/AiRunServiceTest.php \
  tests/Feature/Ai/AiCostAndCachingTest.php tests/Feature/Ai/AiOperationTest.php \
  tests/Feature/Ai/AiAssetOperationsTest.php tests/Feature/Ai/AiChangesetTest.php \
  tests/Feature/Ai/AiTranslationMemoryTest.php tests/Feature/Ai/AiBlockProposalTest.php \
  tests/Feature/Ai/AiLearningTest.php tests/Feature/Ai/AiSpaceDatabaseTest.php
OK (89 tests, 638 assertions)
```

`vendor/bin/pint --test` passed on the changed PHP files. `php artisan schedule:list` shows `*/2 * * * * ai:recover-runs`.

## Not exercised

- True concurrency. Tests run on in-memory SQLite with an array cache and interleave workers in one process, so `lockForUpdate` and competing compare-and-swap updates did not run on MySQL.
- A real worker kill, real `pcntl` alarm or real queue redelivery. Those are simulated by calling `failed()` with the framework's exceptions, by a suspended generator and by time travel.
- A tool crash after its write and before its checkpoint. By reading `ChangesetRecorder`, a replay replaces the pending item on the same path, but no test covers this here.
- Real provider streams and paid calls.
- The migration's `down()`.
