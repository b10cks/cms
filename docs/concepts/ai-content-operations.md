# AI content operations

Status: brief approved by Mike, 2026-10-31. Delivery: `docs/milestones/ai-content-operations.md`. Builds on `docs/concepts/native-ai-integration.md`.

Internal briefing. Excluded from the public docs build (`srcExclude` in `docs/.vitepress/config.ts`).

## Approved brief

An editor states an outcome, approves a concrete plan and gets complete, reviewable operations across up to 10,000 scoped items.

- One run service, the existing threads and the existing feature entry points. AI never publishes.
- The scope is recursive and frozen at approval. Entries that appear later are reported separately, never added.
- Access and versions are rechecked while processing. Every item ends as written (content, in its draft), proposed (a target without a draft), conflict, unchanged, skipped (with a reason), failed, or is still awaiting work.
- Batches are resumable and keep completed work without duplicating it.
- A spending cap pauses the operation. Continuing needs an explicit budget extension. Nothing spends on its own.
- Content goes straight to drafts, and a person publishes when happy: publication is the review. A field someone changed since the agent read it stays open as a conflict for a person: agents get told, further writes to it are refused and the rest continues. Flagged wording blocks nothing. It counts as "needs you" and shows in the content tree and at Publish until the wording leaves the field or the draft is published.
- Assets, folders, tags, data entries and blocks have no draft, so changes to them stay proposals. Their review is grouped, with representative examples and every individual change inspectable. Structural choices (new folders, new taxonomy terms) are approved with the plan. Conflicts, invalid items and flagged uncertainty stay out of bulk acceptance.
- No confidence score is presented as a safety claim: none is calibrated.
- Undo is the existing version history and manual asset correction. There is no group undo.

Assets: the selected folder and its descendants. Inspect images, existing metadata and tags, and propose new terms or folders only when grouped assets justify them, extending existing conventions first. By default fill empty metadata and add tags; overwrites, removals and moves only when the approved plan allows them. Unsupported files are reported. Metadata is validated against the destination folder's rules, and a move and its metadata are accepted together.

Translation: missing locale entries, empty translatable fields and stale translations after source changes. Locale creation and relationships are code. Review shows the source delta and the current translation; updates touch only affected fields and keep unrelated human changes, markup, placeholders and non-translatable fields. Style guides and translation memory apply.

## Skill or code

The rule: skills own editorial policy; deterministic code owns everything that has to hold even when the model is wrong. Nothing in the second column is enforced by a prompt alone.

| Concern | Owner | Why |
| --- | --- | --- |
| Tone, terminology choice, when a tag or folder is worth proposing, how to phrase a translation | Skill | Editorial judgement; varies per space and is what skills are for |
| Enumerating the scope, recursion, the 10,000 limit | Code (`EntryScope`) | Count and walk use the same SQL, so the frozen ledger is exact; a larger scope is refused, never cut |
| Unsupported files | Code (`AiOperationKind`) | Decided by MIME type before any model call |
| Locale destinations, locale creation, i18n relationships | Code (translation domain) | Derived from frozen source IDs and the approved locales |
| Image bytes sent to a vision model | Code (asset domain) | Binary access is a tool, never a model-supplied URL |
| Folder and tag creation, moves | Code (asset domain) | Only structure the approved plan names; checked through `OperationContext` |
| Which entries a batch may write | Code (`ProposalTool::ensureInScope`, `OperationContext::item`) | The batch's frozen IDs; derived creates must name a source entry of the batch |
| Validation | Code (targets, `OperationPlan`) | Invalid proposals return to the model and are never stored |
| Permission | Code (policies, batch recheck) | Rechecked before every batch and between agent steps |
| Budget, pause, extension | Code (`AiOperations`) | Shared budget across batches; a spent budget pauses, only an API call extends |
| Accounting, checkpoints, retries | Code (ledger, `ReportsOutcomes`) | A change to the entry marks it when recorded; tools report the rest, per locale for translations; an entry without an outcome is retried, then failed |
| Which items a group accept may take | Code (`OperationReview`) | Conflicts and flagged items are excluded |

Considered and rejected:

- One long run per operation with 10,000 IDs in the transcript. Token cost grows with every entry, a crash loses context, and the step budget cannot cover it. Batches of bounded runs on the existing loop keep each transcript small.
- Asking the model to report progress as text. Not checkable; the ledger and `report_items` replace it.
- A confidence threshold for bulk acceptance. Uncalibrated, so it would be a false safety claim. Domain tools set qualitative `flags` instead.

## How an operation runs

1. **Create** (`draft`): the scope is resolved, counted and written into `ai_operation_items` as the preview, one row per entry, with `counts.scope_hash`. Unsupported entries are written as `skipped`. Counts by group (block slug, file family) and a rough estimate come back. A thread is opened for the operation.
2. **Plan**: optional planning run in that thread over a sample spread across groups. It loses every tool that proposes changes and gets `propose_plan`, which validates and saves the draft plan. The editor can edit the draft.
3. **Approve**: the client sends the hashes of the plan and the scope preview it showed, and the budget. A plan changed since is refused. The scope freezes here: entries that joined or left it since the preview refuse approval (`scope_changed`) until the editor refreshes the preview and approves its new counts. The approved plan is immutable.
4. **Batches** (`running`): the next pending entries (default 20) are rechecked (deleted entries skip, changed versions are flagged, kind triage may settle entries without a model) and claimed; one background run works on them with the approved plan in its context and `report_items` for entries that need no proposal. All batches share one changeset.
5. **Settle**: after each batch run, written, proposed and reported entries are done; a translation source is done once each approved locale has an outcome. The rest return to the queue and cost an attempt, unless the stop was the budget, a cancellation or lost access. After two attempts an entry fails as `not_processed`.
6. **Pause**: a spent budget, lost access, a failed batch, a cancelled batch run or the editor's request pause the operation. Resume continues; a budget pause needs a budget extension.
7. **Done**: `completed` once no entry is owed. Cancelling leaves owed entries pending, reported as not processed.

## Write contract for domain tools

`OperationContext::forRun($run)` returns null outside operations, so tools keep their normal behaviour there. Inside:

- `item($type, $id)` and `derivedFrom($type, $sourceId)`: an entry of this batch. Merge `derivedFrom()` into every proposal that does not change the entry itself (a locale entry, a translation row); `ChangesetRecorder` refuses a batch change that names no entry of the batch. A derived change does not settle its source: the tool reports that through `ReportsOutcomes`, per part (locale) where an entry has parts.
- `account($outcomes)`: the run service passes every reporting tool's outcomes here after each call.
- `owing($type, $id)`: the batch entry when it owes frozen per-output parts, else null.
- `ensureLocale()`, `ensurePermission('overwrite'|'remove_tags'|'move')`, `plannedFolder($key)`, `isPlannedTag($name)`: the approved plan bounds the write.
- Planning runs: every check refuses; write tools must implement `ProposesChanges` so the planning run drops them.
- Proposals that need an individual look set `flags` (`[{code, message}]`) on the changeset item. Group accepts skip them.
- Plans may name requested outputs: assets `outputs` {fields, tags}, general content operations `metadata` {keys, overwrite_keys, fields, locales}. Triage freezes them per entry in `detail.expected_parts`, from the plan and the real asset field rules or block schema. Each part gets its own outcome in `detail.parts`: proposed, satisfied, intentionally_empty, uninferable, refused, blocked (with a question), invalid or unfinished. A claim about the whole entry settles no owed part. `counts.coverage` summarizes this when the operation stops; without named outputs it says `verified: false`. See `OutputCoverage`.
- Translation batches also carry `setup.translation = {locales, include}` from the approved plan, read by `TranslationPlan::forRun()`.

## Known limits

- The estimate is a token formula until reconciled runs exist; it is not calibrated for vision input.
- Operation spend uses each batch run's live cost estimate; the later OpenRouter reconciliation corrects the run, not the operation total.
- A budget can be overshot by at most one provider turn, as with every run.
- The new-arrivals check walks the current scope on request; it is not pushed.
