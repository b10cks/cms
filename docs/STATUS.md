# Status

Updated 2026-11-05. Read by agents at the start of a thread; rewrite it before handing back. Git is the source of truth.

## Branches

- `main` at `f14e98f8`, unchanged.
- `feat/ai-content-operations-clean` (unpushed): the version to merge. The 8 import commits unchanged, then 4 commits: team and spaces start page, backend, frontend, docs. Its final tree equals `feat/ai-content-operations` plus that branch's uncommitted work, including the new user docs.
- `feat/ai-content-operations` (unpushed): the working branch, unchanged at `84368907`, with its work still uncommitted. See below.
- `archive/ai-content-operations-history` (unpushed): the full 142-commit history at `84368907`, kept for reference. It does not hold the uncommitted work; the clean branch does.
- `feat/import-streaming-progress` (8 ahead of main, unpushed): streaming imports. The clean branch starts from its head, so it can be released first without rewriting anything. Worktree at `.claude/worktrees/import-streaming`, clean.
- Tags `archive/w7-before-reduction` and `archive/w8-before-simplify`: what W8 and W9 removed.

## Merge review, 2026-11-05

Not ready to deploy. One blocker and a list of should-fix items, none fixed yet:

- Blocker: `AiOperations::settleBatch` charges an attempt to every unreached entry when a batch stops on `max_tokens`, `max_steps` or `max_seconds`. After two attempts they fail as `not_processed` unread, and the operation still completes. How often this happens at real page sizes is unmeasured.
- Backend: `OperationReview` loads all items into memory; `TEXT` lineage columns overflow on units over 64 KB; `approve` does full triage inside the request; review endpoints skip the target view check; `startPlanning` takes no lock; `GlossaryMatcher`'s static cache is capped by count, not size; style guides enter the prompt uncapped; wording checks read raw HTML and JSON; `IconDataImportService` yields inside a transaction; unread glossary rows are stored on every run.
- UX: "Reject all" and "Reject selected" have no confirmation; the bulk dialog hands a skill with no prompt to a dialog that requires one; operation panels lack error states and lose unsaved plan edits; `?scope=` strands a leaf team on an empty list.

## Uncommitted work

Glossary improvements on `feat/ai-content-operations`, implemented by Claude Opus and signed off by all three council members after three rounds. Overall scores by round: Opus 8.3 / 9.2 / 9.6; GPT Sol 9 / 9.4 / 9.7; Grok 9 / 9 / 10. No residual blockers or further code changes requested. All 30 changed paths remain uncommitted on this branch and are committed on the clean branch.

- Row saves send `base`, the row as read. Under the glossary lock the writer refuses with 409 `row_changed` only when a field being changed has moved. `changes` now names field and locale, without values.
- After a refusal the sheet reloads the row, keeps typed text, and names the fields where that same reloaded row differs from the typed text. The next Save uses that row as `base`. If the reload fails the old base stays and the message promises no overwrite.
- Only the writer owns the note limit. It refuses a new or changed note over 200 characters with 422 `note_too_long` on every path and keeps an older long note sent back unchanged, compared without outer spaces. The row and `/terms` requests and the sheet importer have no note limit of their own, `withoutStoredNote` is gone, and a sheet reports the refusal under `note`. This tightens `/terms`, which allowed 2000. An older note with outer spaces loses them on its first reimport, which counts as one update.
- Learning no longer shortens wording. It drops a candidate with a term, translation or Avoid over 255 characters, or a note over the limit. Accepting an older pending suggestion like that returns 422 and leaves it pending and unchanged: a validation error under `suggestion` for wording, `note_too_long` for a note. It can only be dismissed.
- A further preferred TBX term lands in Also written as. `conceptWriteError` passes every writer refusal to the sheet.

API spec, space and team MCP descriptions, `management-api.md`, DESIGN.md and the sheet guide are updated. Targeted checks pass: 155 PHP tests, 45 frontend tests, typecheck, oxlint, pint. The refusal messages are tested in the mounted component, not inspected in a browser.

Committed earlier: glossary backend, editor, asset integration, generated API spec and documentation/evidence are committed separately. Generic writes check each destination's language; asset tools return findings; classification refuses offending fields with partial reports. Two real-provider CMS runs passed, 14 assertions. Earlier disposable MySQL migration/retry/rollback and rendered glossary checks passed. The new partial-result toast is unit-tested, not browser-inspected.

## Running

No processes or delegates left by this run. Mike rotated the OpenRouter key. Evaluation finished at 18 synthetic GPT Luna generations, confirmed cost $0.001334. Six of eight baselines had wording violations; none of eight guided outputs did. A substitute environmental claim passed until a note was added, so wording compliance does not establish factual quality. Evidence: `docs/evidence/2026-11-04-glossary-provider-verification.json`. Scratch evaluation files removed; existing local data unchanged.

## Waiting on Mike

- Merge review: which findings to fix before merge, and whether the clean branch replaces the working branch.
- `docs/milestones/reliable-ai-content-operations` and `docs/evidence`: keep on the merged version or leave them on the archive branch only.
- Import branch: approve merging and releasing it. It fixes a live 504.
- AI content operations: merge, push and deploy are not authorized.

## Next

1. After releasing the import branch, run `spaces:repair-databases` by hand for migration 000027, then rerun the 2026-11-03 17:52 metadata file on production.
2. Before deploying this branch, run `spaces:repair-databases` by hand for space migration 000028: AI writes and the thread list fail without it.
3. Review the committed glossary changes and synthetic provider evidence. Before deploying, run management migration `2026_11_05_100000_normalize_ai_glossary_terms` during a controlled worker/task swap; it is not applied locally. Preflight refuses invalid locales; rollback restores the archive and loses later edits. Authenticated full-stack use and broader editorial evaluation remain pilot checks.
