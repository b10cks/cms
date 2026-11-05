# W8 D1 direct writes

Implements [W8](w8-reduce-and-direct-writes.md) task D1. Branch `t3/ai-content-direct-writes`, built on `680a162d`. It replaces the review-before-write rule for content. It says nothing about provider behavior or editorial quality.

## Behaviour as built

**One write path.** Every content tool (`propose_update`, `propose_translation`, `propose_translation_metadata`, `propose_create`) records its change and then calls `AcceptAiChangesetItems::handle()` under the run's actor, inside the tool's fence, through `ProposalTool::writeContent()`. There is no second writer. The item ends `accepted`, with `before`, `after` and `decided_by_id`. Selection proposals and the editor's translate button are unchanged.

I read the task's "new locale entries" with "no content change waits for a person" and "content items lose Accept and Reject", so a brand new default-language entry from `propose_create` is written as an unpublished draft too. No operation reaches this, since `ChangesetRecorder` refuses a target outside the batch. Only a thread can, inside `ai.inline_entry_limit`.

**Other targets.** Assets, folders, tags, data entries and blocks stay proposals, with individual review, overrides and group decisions. Their code is untouched.

**Conflicts.** A field whose current value differs from what the agent read becomes a `conflict` item with `current` set, and the fields around it are written. The tool result carries `conflicts` and `conflict_note`. The agent can re-read and call again: the new item replaces the conflict. If it doesn't, the item stays open with the existing keep-current and use-AI-version flow, the thread shows it needs someone, and the task goes on. A correction of the agent's own earlier text from an old read is not a conflict: the field holds what this changeset last wrote.

`version_id` is now required on content in `propose_update`. It was optional, and without it the code took "what the agent read" to be the current value, which would have overwritten a save made after the real read.

**Findings.** Blocking glossary findings on content are stored on the item and shown, and need no reason. They still block a proposal's accept. The receipt counts them as flagged. They don't put a thread up as "needs you", because nothing can close them except editing the draft. That is my call, say if you want them counted.

**Translation.** `propose_translation` creates a missing locale entry straight away. The first call needs the name, and later calls pass the `version_id` from its result. A row a task created still owes its empty units as `missing`, so a plan limited to missing work can finish a long entry over several calls. Removed: `PendingTranslationTarget`, `ProposedTranslation`, `ProposedTranslations`, `ProposedTranslationView`, `review.derived_from`, `review.derivation`, `review.pending_target`, `derived_from` on `propose_update`, `proposal` on `get_entry` and `get_translation_task`, the `pending_review` hold on content, and the rule that a translation froze once metadata was written.

`proposal.sha256` is replaced by `version_id`. It protected something real, metadata generated from text someone changed since, and the version pin does that with less code. `get_translation_task` returns `draft` (`id`, `name`, `version_id`, `texts`) under a plan that generates metadata. A conflict leaves a person's text in the draft, and metadata reads that. D10 is closed.

**Translation memory.** The task said pairs are recorded on acceptance. They aren't: `TranslationMemory::record()` runs from `LearnFromPublishedContent` after a publish, and from the backfill, and reads only `published_version`. The hit paths read `translation_units` only. So the rule already held, and tests now pin it: applied AI text is no hit, no example and survives a backfill as nothing, published text is a hit, a draft a person edited yields their text with origin `human`, and a new AI draft after a publish leaves the memory as it was. `origin = ai_accepted` now means AI text a person published.

**Learning.** This one was a real bug under direct writes. `EditLearning::fromPublish()` compared each unlearned AI item with the published value, so two AI writes to one field made the second look like a person's edit of the first. `EditLearning::supersede()` now marks earlier writes of the field as learned, the way the translate button already did. A draft a person edited still teaches.

**Ledger.** New outcomes and statuses `written` and `conflict`. `written` counts as fulfilled and `conflict` does not, so partial output can't report complete fulfilment. A unit that hit a conflict becomes its own `<locale>#<unit>` part, so the rest of the locale goes on. `detail.units` is now `{written, remaining}`. An operation finishes on its own once nothing is owed: there was never a gate in `step()`, and the holds came from triage reading pending items, which are gone for content.

**No undo.** Each write creates a version with `created_by_id` set to the actor and `message` set to `AI: <changeset title>`. `CreateContent` now takes `message`, so a new locale entry's first version says the same. Each entry links to `/content/<id>/versions`.

**Open editor.** See the case below.

**Permissions.** A content tool checks `content.manage` first and returns a clear tool error. Approving a content operation needs `content.manage` (`AiOperationPolicy::approve`), so approving never does what the approver could not do by hand. A batch runs under the operation's actor, and `mayRun` needs the same right on content, so an actor who lost it pauses as `access_revoked` before the next batch.

**API.** `GET …/ai/operations/{id}/changes` lists changed entries, conflicts and flagged first, with `summary`. `…/review` and group decisions cover proposals only. `…/review/items` takes `target_id` for one entry. MCP has `ai.operations.changes`. Schema, spec, `management-api.md`, `readiness.md`, concept docs and skills follow.

**UI.** The receipt reads "Changed 412 entries, 3 conflicts, 7 flagged", then what waits, then "Nothing is published". `AiOperationChanges` lists entries with links to draft and history, and opens into fields on request. `AiThreadChangeset` has the same line and puts entries that need someone first. The draft step says once what happens to content. Rule added to `DESIGN.md`.

## What each failure does

| Case | What happens |
| --- | --- |
| Person saved the same field after the agent read it | Field stays theirs, item `conflict`, agent told, rest written |
| Person saved another field | Their field stays, agent's field written on their version |
| Person saved a translation after the agent read it | Tool error with nothing written, agent re-reads. Their text then shows as held, so it stays |
| Person saves while the agent holds the row | They wait for the commit, then get the editor's 409 dialog with their typing intact |
| Person creates the locale entry during the tool call | Tool error, nothing written, their entry stays |
| Person has the entry open, nothing unsaved | `content:updated` refetches, the draft arrives, the editor re-anchors so a later save doesn't falsely conflict |
| Person has the entry open, with unsaved typing | `preserve-local` keeps it, the history button marks drift, a save gets the 409 dialog |
| Worker killed after the write, before the commit | Draft, item, ledger and result all roll back, and the next owner repeats the call once |
| Lost lease | `RunLeaseLost` in the fence, as in W6, and nothing lands |
| Deadlock inside the write | Rolls back, `RunBusy`, the next owner replays |
| Actor may not edit | Tool error, nothing written |
| Actor lost rights mid-way | That call refused, operation pauses before the next batch |
| Approver may not edit | 403 on approve, nothing starts |
| Schema changed since validation | Tool error from the savepoint rollback, nothing left behind |
| Budget spent | Pauses as before, written work stays, extension needed |
| Agent gives up on a conflict | Part stays `conflict`, operation completes, coverage says incomplete |
| An old conflict in another changeset | No longer holds up a new task. The new task reads the current value |
| Proposal target with a blocking finding | Needs a correction or a reason, as before |

The race in the first row found a bug SQLite can't show. Tools run in the fence's transaction, so the accept path's plain read after its lock could read a snapshot from before the person's save. `ContentTarget::locked()` now reads the row and the version with locking reads. With the plain read put back, the same race raised a `TypeError` in `ContentVersionConflictException`, because the new version was invisible to the snapshot.

## Removed or rewritten tests

Deleted, because they asserted what no longer exists: `a_proposal_records_the_exact_translation_it_derives_from_and_acceptance_ignores_it`, `a_rejected_translation_never_falls_back_to_older_text_and_nothing_cascades`, `get_entry_shows_what_is_stored_and_adds_the_proposal_of_this_task`, `an_accepted_new_translation_entry_is_recorded_as_accepted_ai` (replaced).

Rewritten for direct writes, by file:

- `AiChangesetTest`: accept-creates-draft, field conflict, edit rights, bulk accept (seeded open items), block list, thread status, preflight (estimate taken before the run writes).
- `AiAcceptanceReviewTest`: findings on content block nothing. Reason, hint and frozen-policy rules moved to data entry proposals.
- `AiAcceptanceAtomicityTest`: queued slice versus HTTP accept moved to a data entry.
- `AiOperationTest`: group accept and queued group moved to data entries, statuses, thread status.
- `AiOperationQuestionsTest`: `an_answered_output_stays_visible_when_another_proposal_prevents_its_retry` became `an_open_item_of_another_changeset_does_not_hold_up_an_answered_content_output`.
- `AiMetadataOperationsTest`, `AiOperationCompletionTest`, `AiProviderRecoveryTest`, `AiRunRecoveryTest`, `AiLearningTest`, `AiTranslationMemoryTest`, `AiTranslationOperationsTest`, `AiTranslationMetadataTest`, `AiContentOperationsMcpTest`, `AiContentOperationsSpecTest`.
- Frontend: `AiThreadChangeset`, `AiChangesetReview` (fixture is now a data entry), `AiOperationPanel`, `aiChangesets`, `aiOperations`.

New: `AiDirectWritesTest` (16 tests), plus memory, learning, recovery and MCP additions.

## Checks

```text
vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation    OK, 452 tests, 5,076 assertions, 1 skipped
bunx vitest run tests/js/components/ai tests/js/lib tests/js/components/localization tests/js/components/editor   70 files, 1,066 tests passed
bunx vue-tsc --noEmit                                     clean
bunx oxlint --deny-warnings on changed paths              clean
vendor/bin/pint --test on changed PHP                     passed
```

**MySQL.** The W6 harness was deleted with W6, so I wrote a small scratch one, outside the repo. MySQL 26.10.0, InnoDB, `REPEATABLE-READ`, private `mysqld` on a socket, real `RunLease::fence`, the real tools, and the person as a separate PHP process on its own connection. Fake or no provider. 19 of 19 checks passed:

- Stale snapshot: the person saves mid tool. Their text stays, the other field is written on their version, the item is a conflict holding their value, the agent is told, three versions exist.
- The same race with the plain read put back fails, so the check bites.
- JSON key order on a real JSON column: an unchanged meta field writes without a conflict, and unrelated keys stay.
- A person's save during the agent's write waits on the row (1 lock wait in `performance_schema.data_lock_waits`), then answers `version_conflict`.
- A person creating the locale entry mid tool: failed at first with two rows for one locale, which is how I found that case. Now the tool refuses and theirs stays.
- SIGKILL after the write and before the commit: the draft rolls back and no item stays. The repeat writes once, on top of the same version.

**Visual.** Real `AiOperationReceipt`, `AiOperationReview` and `AiThreadChangeset` with real CSS on a throwaway Vite page on port 5197 with a mocked API, Playwright with the cached Chromium. Light at 520 and 390, dark and German at 520. No horizontal overflow and no console errors. Looking found one defect: a long entry name inflated the card at narrow widths. Fixed with `min-w-0` down the tree and a wrapping stats row. The page is gone and my server is stopped.

## Not exercised

- **The open editor in a browser.** The arrival and `preserve-local` paths are read from the code, and the 409 from the harness. The one-line re-anchor in `index.vue` has no test, since no test mounts that page.
- A real Reverb broadcast. `ContentUpdated` is `ShouldDispatchAfterCommit`, a test checks it is dispatched with no socket, and W6 checked after-commit behavior.
- Real providers, so whether a model re-reads after a conflict as the tool result asks.
- The dock in the authenticated app. German on the page's global copy: the fixture doesn't load the catalog, so global strings showed English there. They are in the catalog and typecheck.
- A mixed operation end to end: no tool mixes kinds inside one batch, because `ChangesetRecorder` refuses a target outside it. The test seeds a data entry proposal into a content operation's changeset. A thread run does both for real and is tested.
- Postgres spaces, a real queue daemon, and a lost connection inside a fence.

## Limits and where human text can still be lost

- **Two inserts of one locale entry.** `contents` has no unique index on source and language. The locking read closes the agent's side, but a person's create that passed its validator just before the agent's commit can still insert a second row. Nothing is overwritten, but there are two rows. A unique index would close it and needs a data check first, so I left it.
- **Pinned translations are coarser than fields.** Any save of a translation after the read refuses the whole call. That is safe, but a person saving often can use up the attempts, and the locale ends `unfinished`.
- **A retry is allowed to replace a person's text.** After a conflict the agent may read again and write over what it read, as the task says. The earlier value stays in version history, and the item records it as `before`.
- **The editor's own conflict dialog.** "Overwrite" there replaces the AI's draft or the person's unsaved text, whichever they pick. That is a person's choice, as before.
- **A resolved conflict doesn't change frozen coverage.** `counts.coverage` keeps `conflict` as the run ended. The receipt's line reads live items.
- **Learning across overlapping paths.** A list-level write and a nested write of the same block are different paths, so `supersede` doesn't link them. An AI list write over an earlier nested AI write could read as a person's edit. It is rare, and it costs one glossary suggestion, not text.

## Follow-ups noticed

- `AiChangesetController::accept` and the group job still clear `X-Socket-ID`. `decide()` no longer calls `toOthers()`, so the header removal only matters for the asset applier. Check and drop it if unused.
- `fr`, `es`, `ru` and `tr` got new keys so the catalog types still match. I wrote those translations and nobody reviewed them.

## Cleanup

Scratch harness and fixture lived outside the repo and are deleted. My `mysqld` was stopped with `mysqladmin shutdown` and confirmed gone, and my Vite server was stopped by its PID. The system `mysqld` and all real databases were not touched.
