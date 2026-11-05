# Milestone: AI content operations

Design: `docs/concepts/ai-content-operations.md`. Integration branch: `feat/ai-content-operations`.

Status 2026-10-31: orchestration, translation, assets and frontend integrated on `feat/ai-content-operations`. All task branches and worktrees removed after integration. Main is unchanged. API schema metadata/spec and this handover await commit approval.

## Orchestration: delivered

Implemented, integrated and checked on `feat/ai-content-operations`.

- Space migration `0000_00_00_000021_create_ai_operations_tables.php`: `ai_operations`, `ai_operation_items` (the ledger), `ai_runs.operation_id`, `ai_changesets.operation_id` (unique, one changeset per operation), `ai_changeset_items.operation_item_id` and `flags`.
- `EntryScope::count()`, `each()`, `describe()`, `folderTree()`, `overflows()`: recursive scopes walked in ID-ordered chunks with SQL filters (`block`, `language`, `missing_locale`, `query`, `data_source`). A root is a parent (its children, all descendants when recursive); explicit IDs count themselves plus descendants when recursive. Legacy `resolve()` is unchanged.
- `AiOperations`: create and freeze, planning run, plan save, approve, batch claim and launch, settle, pause, resume, budget extension, cancel, new arrivals, crash recovery.
- `OperationPlan` validation per kind, `OperationContext` write contract, `OperationTriage` extension point, `OperationReview` grouping.
- Core tools added in code by `OperationTools` (not through `RunToolFactory`): `propose_plan` for planning runs, `report_items` for batch runs. Planning runs drop every `ProposesChanges` tool; batch runs drop `ask_questions`.
- `StartRun` gains `operation` and `translation`; `AiRunService` freezes both in `setup` and adjusts operation tools. `StartRun::TARGET_INSTRUCTIONS` no longer names `propose_update`.
- `RunAgentJob` hands finished operation runs to `AiOperations::runFinished()`, also from `handleFailure()`.
- `ChangesetRecorder` writes all batch proposals of an operation into one changeset, links each to its ledger entry and marks it `proposed` at once.
- `PreflightEstimate::forTotals()`; observed cost per entry ignores operation runs. The preflight reports `truncated` and `max_entries` when a flat scope exceeds 500 entries.
- Schedules: a scope over 500 entries starts an auto-approved `general` operation (schedule name as the plan, `max_usd` as the budget, the schedule's thread). A tick is skipped while its last operation is running or paused. A scope that needs a PHP-only filter (`missing_dimension`, `missing_field`) or exceeds 10,000 pauses the schedule with `scope_too_large`.
- `AiChangesetPolicy`: an operation's changeset follows the operation's visibility.
- Config `ai.operations`: `max_items` (10000), `batch_size` (20), `max_attempts` (2), `plan_sample` (20), `triage` (kind => class).

## API contract

All under `/mgmt/v1/spaces/{space}`, behind `space.member`. An operation is private to its actor; one a schedule started is visible to members with `ai.manage`. Steering needs the run-start ability of its surface (`bulk:content`, `bulk:assets`, `bulk:data_entries`).

| Method and path | Body or query | Response |
| --- | --- | --- |
| `GET ai/operations` | `conversation_id?` | The latest 50 operations the user may view: their own, and with `ai.manage` those schedules started |
| `POST ai/operations` | `kind` (`translation`, `assets`, `general`), `prompt`, `skill?`, `config_id?`, `conversation_id?`, `scope: {type, ids?, root_id?, recursive? (default true), filters?}` | 201 operation in `draft`. 422 `{reason: scope_too_large, count, limit}` above `max_items`; nothing is frozen. 422 for an empty scope or a kind/type mismatch |
| `GET ai/operations/{id}` | | Operation |
| `PATCH ai/operations/{id}` | `plan` | Operation with the new `plan_hash`. 422 `plan.*` field errors. 409 once approved |
| `POST ai/operations/{id}/plan` | | 202 `{data: run}`; follow with `GET ai/runs/{run}/events`. 409 once approved. 429 `concurrency_limit` |
| `POST ai/operations/{id}/approve` | `plan_hash`, `scope_hash` (the `counts.scope_hash` the editor saw), `max_usd` | Operation, `running`. 409 `plan_changed` when the draft changed since that hash. 409 `{reason: scope_changed, added, removed}` when entries joined or left the scope since the preview, or `scope_hash` is not the ledger's; nothing is approved. 429 `concurrency_limit` |
| `POST ai/operations/{id}/scope` | | Draft only. Freezes the scope again as it is now; operation with new `counts` and `counts.scope_hash`. 422 `scope_too_large` or an empty scope. 409 once approved |
| `POST ai/operations/{id}/pause` | | Pauses after the current batch (`pause_requested`), or now |
| `POST ai/operations/{id}/resume` | | Continues a paused operation, or re-dispatches a running batch that lost its worker. 409 `budget` when the budget is spent |
| `POST ai/operations/{id}/budget` | `max_usd` (new total, above `spent_usd`) | Operation; resumes a budget pause |
| `POST ai/operations/{id}/cancel` | | Operation, `cancelled` |
| `GET ai/operations/{id}/items` | `status?`, `reason?`, `page`, `per_page` (max 200) | Paginated ledger entries |
| `GET ai/operations/{id}/arrivals` | | `{count, entries: [{id, name}]}` (first 20) |
| `GET ai/operations/{id}/review` | | Groups (below) |
| `GET ai/operations/{id}/review/items` | `group`, `individual?` (only items a group accept leaves open), `page`, `per_page` | `{data: changeset items, meta: {total, page, per_page}}` |
| `POST ai/operations/{id}/review/accept` | `group` | 200 `{accepted, conflicts, failed, blocked, individual, queued: 0}`; `failed` entries did not validate and are now flagged `invalid`; `accepted` includes planned folders and tags created with their first change. 202 with `queued: n` when more than `review_sync_limit` (200) items qualify: a job decides them in slices. The group shows `queued: "accept"` until the job is done or failed; the operation's thread gets `AiThreadChanged` after each slice and once more when `queued` clears. Refetch `GET review` on that event, or poll it every few seconds, until the group's `queued` is null. A queued state no slice renewed for 10 minutes clears itself |
| `POST ai/operations/{id}/review/reject` | `group` | 200 `{rejected, queued: 0}`; all open items of the group. 202 `{rejected: 0, queued: n}` above the limit, as for accept |

Individual items are accepted and rejected through the existing `POST ai/changesets/{changeset_id}/accept|reject` with `item_ids`. `bulk: true` on accept applies the group rule there too: items that need an individual look stay open, and an entry that fails validation is flagged `invalid` instead of failing the request. The rule is `DecidableItems::needsIndividualLook()`: conflicts, glossary findings, `flags`, and for asset items `AssetReview::excludedFromBulk()` (new folders and tags, review flags and problems).

Operation resource:

```text
id, kind, state (draft|running|paused|completed|cancelled), pause_reason (budget|user|access_revoked|batch_failed|batch_cancelled|null),
pause_requested, error, skill, surface, prompt, conversation_id, schedule_id,
scope {type, ids, root_id, recursive, filters},
counts {total, supported, unsupported, groups {group: n}, tokens, estimate {input_tokens, output_tokens, cost_usd}, scope_hash},
plan, plan_hash, plan_run_id, approved_plan, approved_plan_hash, approved_at,
budget {max_usd, spent_usd, remaining_usd}, usage {input_tokens, output_tokens},
progress {by_status {pending, running, proposed, unchanged, skipped, failed}, owed, reasons {skipped: {code: n}, failed: {code: n}}, batches},
current_run {id, state} | null, changeset_id,
changeset {id, title, status, inline, operation_id, item_count, pending_count, created_at} | null,
created_at, finished_at
```

`changeset` is `AiChangesetResource` without `items`, `run` or `actor`: the counts an operation without a planning turn needs to show its review. Items load per group through `GET review/items`, never as one changeset.

Ledger entry: `id, position, target_type, target_id, label, group, status, reason, note, version_changed, attempts, batch, run_id, parts, proposals`. `parts` is `{locale: {outcome, reason?}}` for translation entries (`{}` otherwise): a source is done once every approved locale has an outcome. Reason codes: `unsupported_file_type`, `deleted`, `agent` (the run reported it; `note` holds its words), `not_processed`, `batch_failed`; translation triage sets `up_to_date`, `pending_review` (a proposal waits in another changeset), `not_in_plan`, `not_in_default_language`, `no_block`; asset triage sets `pending_review` and `complete`.

Review group: `key, label, operation, target_type, path (IDs folded to *), field_type, counts {pending, accepted, rejected, conflict}, bulk, individual, queued (accept|reject|null), examples (up to 3 changeset items, ones a group accept takes first)`. Asset items group by their review label (`key` `asset|<label>`, `label` set); new folders and tags form the `structure` group, listed first. Changeset items carry `flags`, `operation_item_id`, `review` (asset domain) and `translation_units` (`[{unit_id, reason, source, previous_source}]`). The changeset carries `operation_id`; the thread reviews such a changeset in groups and needs no `items` for it.

Plan shape, validated per kind:

- every kind: `summary` (required), `rules` (list), `options` (named scalar values for domain settings)
- `translation`: `locales` (enabled, not the default), `include` (`missing`, `empty`, `stale`, `same_as_source`)
- `assets`: `permissions {overwrite, remove_tags, move}` (default false), `overwrite_fields` (field keys an overwrite may replace; required exactly when `overwrite` is true), `languages` (metadata language keys to write, `_default` for the default language; empty writes all), `structure {folders: [{key, name, parent_id | parent_key, reason, examples}], tags: [{name, reason, examples}]}`. Examples must be frozen asset IDs; a planned folder sits under a scope folder or another planned folder and needs `move`; a name an existing folder (same parent) or tag already has is refused with its ID.

## Runtime integration: delivered

Implemented on the integration branch, with grouped review and frontend contracts connected.

- Scope freezes at approval. The draft ledger is the preview; approval walks the scope again and refuses a different one with `scope_changed`. `POST scope` refreshes the draft, the editor approves the new `scope_hash`. Entries that arrive after approval stay out and show under `arrivals`.
- Ledger accounting runs through `ReportsOutcomes`: the run service hands every reporting tool's outcomes to `OperationContext::account()` right after the call, before the checkpoint. A direct change to a batch entry marks it `proposed` in `ChangesetRecorder`; a derived change (translation row, locale entry) must carry `derivedFrom()` or the recorder refuses it, and does not settle its source. Translation sources settle per locale (`parts`): `propose_translation` and `propose_create` report a locale once no unit of it is left to propose; `find_translation_work` and `get_translation_task` report current, waiting and out-of-plan locales; `report_items` covers the locales still open. Retried batches show settled locales under `targets.entries[].done`.
- Triage per kind (`config('ai.operations.triage')`): `TranslationTriage` settles current, waiting and out-of-plan locales before the model; `AssetTriage` skips assets with proposals waiting elsewhere and, under a plan that only fills metadata and adds existing tags, assets that are tagged and have every AI field filled in the plan's languages.
- Asset plans: a batch freezes `setup.plan.assets` (`AssetPlan::forOperation()`) from the approved plan. Planned folders and tags become structure proposals of the operation's changeset once, before the first batch (`PlannedAssetStructure`); batches use their `new:` refs, `propose_asset_structure` hands out only those, and the applier creates each with the first accepted change that uses it. Field writes, overwrites, removals and moves follow the approved plan.
- Images: `InspectionImages::append()` after all tool results of a turn, `compact()` before every provider call. A run that pauses for questions in the same turn drops the images and tells the resumed model which assets to inspect again.
- Review: asset groups by review label (grouped-review branch); a move and its metadata stay together in bulk selection and chunking; chunks keep each entry's items in one accept; large group decisions run on the queue (`DecideAiOperationGroupJob`).
- Threads: a running operation makes its thread `running`, its open changeset `needs_review` and counts in `entry_count`. The operation's changeset shows on the thread's last turn of that operation (its planning run), without `items`.
- `SpaceDatabaseMigrator` proves the review and operations migrations by column (`ai_changeset_items.review`, `ai_changeset_items.flags`).

## Integration and deployment

- Domain tools, operation accounting, triage, asset bundles, thread status, approval hashes and queued review are connected.
- Translation lineage reads history in bounded chunks without a version cutoff. Reviewer-edited accepted values retain the proposal's source snapshot, so a later source change still marks them stale.
- The three space migrations share the 000021 prefix but have distinct filenames. Migrator tests cover the tables and review/flags columns; no renumbering was needed.
- Deploy: run `spaces:repair-databases` by hand after tagging. No application database migration was run during implementation.
- The local Management API spec includes operation routes, scope refresh, bulk review and the lightweight changeset. Resource metadata preserves the lazy changeset items as an array.
- External MCP catalog and sibling SDK exposure remain a follow-up. Their operation registries stay unchanged and the existing mirror check passes. Changing the sibling repo needs separate authorization under the user's repo boundary.

## Frontend integration: delivered

Status 2026-10-31: on the integration branch.

- Approval sends `{plan_hash, scope_hash, max_usd}` (`AiOperationApproval`). A 409 `scope_changed` shows the added and removed counts inline with "Recount the scope" (`POST scope`) and keeps approval closed. After the recount the draft shows the new and old totals; the editor checks counts, estimate and plan and approves again with the new hashes. Nothing approves on its own. A recount answered with `scope_too_large` or an empty scope keeps approval closed and offers to cancel the operation.
- Asset plans: under "Replace existing values" the editor picks the fields an overwrite may replace by label, from the space's asset fields and the folder settings of every folder in scope (the same union the server validates). "Languages to write" lists the asset languages by name, `_default` as the default language; none picked writes every language. `planPayload` sends `overwrite_fields` only while overwrite is on; the draft keeps them for toggling back. The approved plan shows the same choices as names.
- Group decisions: `queued` sums across the selected groups and shows as a background toast, never as "0 accepted". A queued group says it is being accepted or rejected in the background, leaves the selection and takes no group or individual decision. The review polls every 3 seconds while a group is queued; `AiThreadChanged` refetches the operation, whose changeset counts refetch the review. When a queued accept clears, the entries of that group's type refetch.
- No planning turn: the panel reviews the operation's `changeset` summary when no turn carries it. Thread decisions refetch operations too, so its counts stay current.
- Polling: the ledger refetches when `progress.by_status` or the batch count changes; the review when proposals, `item_count` or `pending_count` change. A review fetch in flight is reused.
- Ledger entries carry `parts` in the type; the ledger does not show per-locale outcomes yet.
- Tests: `tests/js/components/ai/operations/AiOperationPanel.test.ts` (hash approval, recount and re-approval on request, too large after recount, asset field and language choices and payload, approved asset plan, review from the changeset summary refetched as proposals arrive), `tests/js/components/ai/threads/AiThreadChangeset.test.ts` (queued accept toast, locked group, poll until done), `tests/js/lib/aiOperations.test.ts`, `tests/Feature/Ai/AiOperationTest.php` (changeset summary without items).

## Checks

- Final integration: 141 PHP tests, 2,344 assertions across `tests/Feature/Ai`, `tests/Feature/Mcp` and `SpaceDatabaseMigratorTest`.
- Final integration: 164 frontend tests across the AI components, operation/changeset/thread helpers, query keys, API registration and space broadcasts. Typecheck and lint pass.
- The 10,000-entry test uses 42 bounded batch runs with failure/retry and accounts for each entry once. Asset tests verify images reach the driver after tool results and proposed moves/metadata apply together over HTTP. Translation tests cover multiple locales, missing entries, empty/stale fields, history beyond 50 versions and reviewer-edited lineage.
- Launch, draft, asset structure, overwrite/language choices, budget pause, recount warnings/results and queued review inspected in light/dark component fixtures with Chromium. Fixture servers and files removed.
- Management API spec regenerated for this feature's paths/schemas. Lazy items, lineage/review fields and local schema references checked. The generator still has limited inference for nested dynamic response fields.

## Not done or not verified

- No real-model evaluation (deferred by the brief); tests use the fake driver.
- MySQL not exercised: tests run on SQLite. The review grouping and ledger queries use plain SQL that both support.
- Performance at 10,000 entries is measured only on SQLite with the fake driver (about 8 seconds for 42 batches). The review loads one row per changeset item on every group call; at tens of thousands of items that wants a stored group key.
- Claiming a batch triages candidates in a loop; a translation scope where nearly every entry is current triages many chunks under the operation lock in one step.
- No sweeper for a batch whose worker died without `handleFailure()`; `POST resume` recovers it.
