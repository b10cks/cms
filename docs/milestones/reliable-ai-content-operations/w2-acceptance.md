# W2 atomic acceptance evidence

Branch `t3/atomic-ai-acceptance`, built on `6c351e9f`. One slice only. It does not complete the brief.

## Risks reproduced on the old code

The scratch harness described under "Checks" ran against the code at `6c351e9f`, on real MySQL with real processes:

| Scenario | Result on the old code |
| --- | --- |
| Six processes accept one create item | Six content rows, `accepted` summed to 6 |
| Accept holds its transaction, reject arrives later | The check that reject waits and finds the item closed failed. The accept reported 1 and the item ended accepted |
| Worker gets SIGKILL after the target write | Version written, proposal still pending. The retry then reported a conflict against its own write |
| Editor save still uncommitted when accept arrives | 409 for the whole request |
| Three bulk accepts and one reject over 30 entries | 409 errors, 60 accepted plus 30 rejected for 60 items, stored `pending_count` 30 with status `applied` |
| Four accepts of six asset bundles into one proposed folder | Four folders, 36 acceptances instead of 13 |

## Behavior now

**One transaction per target.** Writing an entry and recording the decision commit together on the space connection. That covers content, data entries, blocks, assets, new entries and new blocks. A lost worker leaves neither.

**Lock order.** Set in `DecidableItems` and used by every path:

1. Items by primary key, in ID order.
2. The one target row.
3. Rows the decision creates.

No path holds an item lock while taking another target's. The first statements in a transaction are its locks, because under REPEATABLE READ the first plain read fixes what later plain reads see. Lookups the locks depend on run before the transaction. Deadlocks retry up to three times, and each attempt re-reads everything.

**Re-read under lock.** Status, flags, findings and bulk eligibility are read again once the rows are locked. A closed item is skipped, and a bulk accept leaves an item flagged since it was selected. A repeat of a create finds the item accepted and creates nothing, and the item's `target_id` is the established result.

**Field conflicts instead of 409.** The target is locked before it is read, so an editor's save shows up as a field conflict with the current value. Content can no longer hit a version conflict on this path. `ContentTarget::apply` still maps one to a 409 as a safeguard.

**Accept and reject serialize on the same rows.** Whichever commits first wins, and the other reports 0 for those items. Reject takes chunks of 200, so a large reject does not hold the rest of an operation.

**Queued and HTTP decisions** use the same row locks. The job's cache lock now only keeps slices from taking turns on the same items. The job comment says so. It stays `tries = 1` on purpose, see "For Mike".

**Assets.** Each asset is one transaction over its selected items, its bundle, the proposals it refers to including parent folders, and the asset row. New folder and tag proposals accepted alone get their own transaction. `review.evidence` is preserved.

**After commit.**

- `ContentUpdated` is muted during the transaction and sent once after commit, so a rollback or a deadlock retry announces nothing.
- Learning and thread broadcasts are `rescue`d. If they fail, the failure is reported and the accept still answers 200.
- The recount runs under a row lock on the changeset, in `finally`, so decisions committed before a later target threw are counted.
- Reviewer lineage (`recordEdits`) moved into the transaction, because it records what the decision applied.

Reviewer edits, schema revalidation, version history, authorization, glossary and flag rules, and bundle and dependency behavior are unchanged. D10 and violation overrides are untouched.

## API and schema

- Response shapes and the `accepted`/`conflicts`/`failed`/`blocked`/`queued` semantics are unchanged.
- One outcome moves: a save racing an accept returns a field conflict with a 200 where it returned a 409.
- A non-bulk validation failure now rolls back that entry's conflict marks with its write. The item stays open and the request answers 422 as before.
- `AssetChangesetApplier::accept` gains an optional trailing `$bulk`.
- No migrations and no deploy steps.

## Checks

| Check | Result |
| --- | --- |
| `phpunit tests/Feature/Ai/AiAcceptanceAtomicityTest.php` | 14 tests pass. On the old acceptance code 9 of 14 fail |
| `phpunit tests/Feature/Ai` | 170 tests, 1,032 assertions pass (SQLite, fake driver) |
| `phpunit --filter 'Translation\|Mcp\|Classification' tests/Unit` | 15 tests, 32 assertions pass |
| `pint --test` on all changed PHP | Passes |
| Multiprocess harness on MySQL 26.10.0, REPEATABLE READ | All scenarios pass on 4 of 4 runs |

The committed tests run on a separate SQLite space database, so their rollbacks are real rollbacks of the space connection. SQLite has no row locks, so those tests run competitors inline after a decision's unlocked read. They prove the re-read under lock and not the blocking.

The multiprocess harness is what exercises blocking. It was a scratch script against a private throwaway `mysqld` on its own datadir, port and empty env file, and both were deleted afterwards. It called the real actions from separate PHP processes, with authorization stubbed. Beyond the table above it covered reject-first and a real SIGKILL followed by a retry. No process failed with a deadlock error, but retried deadlocks were not counted.

## What was not exercised

- Production database or a real queue worker. `DecideAiOperationGroupJob` ran inline, in the SQLite test only. The harness covered the action it calls.
- A SIGKILL inside an asset transaction. It uses the same transaction, but only content was killed.
- Data entry acceptance under real concurrency. It shares the code path with content.
- PostgreSQL.

## Remaining acceptance gaps

- A worker dying after commit and before the recount leaves stored counts stale until the next decision on that changeset. Item rows stay correct.
- A worker dying in that same window loses the learning job for that accept. The reviewer's edit and its lineage are kept.
- Two different proposals with the same new folder or tag name, from separate runs, can both create it when they race. Name reuse reads without a lock and there is no unique index. The same proposal is safe. Fixing it needs a schema decision.
- Rows from accepts that died before this change are not repaired: a written entry with a pending proposal stays that way until someone decides it.

## Integration requests

- `SpaceModelChanged` still broadcasts inside the transaction for assets, data entries and other trait users. A rollback can announce a change that did not commit, and clients only refetch. Recommend `ShouldDispatchAfterCommit` on that event and on `ContentUpdated`. That would also cover other write paths and make the mute here removable.
- `Content::saved` dispatches `UpdateContentFullSlugsJob` directly, with queue `after_commit` false. For a created entry the job can run before commit, or after a rollback, and then fail on a missing model. The same risk existed inside `CreateContent`'s own transaction.
- `AiAcceptanceAtomicityTest` drops the `space_` prefix from the test connection, because SQLite cannot run the asset usage JSON queries with it. An optional prefix argument on `setUpSeparateSpaceDatabase` would remove the second migrate there.

## For Mike

The job stays `tries = 1`. Slices are now safe to retry, but `retry_after` is 3720s, so a retried job would accept a group about an hour after the editor last looked at it. Today a lost worker leaves committed items decided and the editor clicks again. I kept that because a human decision should not complete itself an hour later. Raise `tries` if automatic resume matters more.
