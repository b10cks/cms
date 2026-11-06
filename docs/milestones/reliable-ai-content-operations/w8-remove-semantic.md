# W8 R1: semantic review removed

Task R1 of [W8](w8-reduce-and-direct-writes.md). The separate semantic review pass is gone from backend, UI, skills, MCP, API docs and spec. No flow waits for it. Historical evidence files such as `w3-semantic-review.md` stay as they were.

## What went

- `app/Services/Ai/Review/*` and `resources/ai/skills/semantic-review`, with its surface in `SkillParser`. `skillManifest.ts` had no reference.
- Run wiring: `StartRun::semanticReview`, `AiRunService` after-run hook and memory review in `result`, the sweep in `RunRecovery`, the `ai.semantic_review` config.
- Operations: `startNext`, review scheduling between batches, `closeReviews`, `counts.semantic_review` and pausing for pending reviews.
- Acceptance: `rechecking`, `requestRecheck`, `review.semantic` and `review.semantic_edit`, and the bulk exclusion for review states.
- Translation memory: `MEMORY_SLOT`, `unverified`, `unverified_sources` and the `memory_unverified` tool field. Exact hits follow the deterministic checks only.
- Frontend: `AiSemanticState`, `AiSemanticSummary`, `AiRunSemanticNotice`, `memoryReviewRunId(s)`, `itemTextReview`, `itemWarnings`, `reviewPollInterval`, `semanticCounts`, `useRunReviewQuery`, all related types and i18n keys.
- Schemas `AiSemanticFinding`, `AiSemanticReview`, `AiSemanticEdit`, `AiChangesetAccepted.rechecking`, `AiRunResult.semantic_review` and the semantic fields of `AiReviewFlag`. MCP descriptions and `mgmt_v1.json` follow.
- Tests that only covered the pass: `AiSemanticReviewTest` (three acceptance tests kept, see below) and the matching JS cases.

`git diff --shortstat` against the base: 67 files, about 3800 lines removed and 500 added. Of the removals, 2381 are app code, 908 tests and 488 the generated spec.

## What moved

Three helpers in the deleted classes were not semantic:

- `findingId` is now `GlossaryChecker::findingId`.
- `text` is now `App\Services\Ai\Changesets\ProposalCopy`.
- `sourceOf` is a private method of `AcceptanceReview`.

The acceptance tests for reasons, hints and edits moved to `tests/Feature/Ai/AiAcceptanceReviewTest.php`.

## The `review` column stays

It still holds policy provenance, `derived_from`, `derivation`, `pending_target`, overrides, evidence and the asset review keys. Migration `000021` stays unchanged.

## What surprised me

- `review.semantic` was also where the deterministic recheck of an edit found its locales. Removing it would have checked edits in the wrong language or not at all, and no test failed on that alone. Locales now live in a new `review.locales` (`{source, target}`), written by `ChangesetRecorder`, `TranslationProposer` and the metadata tool. Inline proposals and values without copy still record none, so their edits stay unchecked as before.
- `review.semantic_edit` was the only server copy of a reviewer's edit. Edits now live in the browser until accepted, so a reload loses an unaccepted edit. It used to survive only because the recheck stored it.
- An edit with no findings now applies on the first accept, individually and in bulk. It used to stay open for a recheck where rules applied.
- A contextual term on an exact memory hit no longer reaches the agent or the editor as a signal. This follows from the task, and is worth knowing for D1 and P1.
- Provenance ("Knowledge used") was rendered inside `AiSemanticState`, so `AiItemReview` now renders it itself, with the same toggle.

## Left for others

- `RunRecovery::sweep` still calls `KnowledgeImpactScanner` and `PolicySnapshot` keeps its document rules. Those belong to R2. No file R2 deletes was edited.
- `DecidableItems`, `AcceptanceReview` and the overrides flow are otherwise unchanged for D1.
- `README.md` in this folder still describes the semantic pass in its wave narrative and work log. That is a record of what happened, so it stays.

## Checks

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation` | 479 tests pass, 1 skipped |
| `bunx vitest run tests/js/components/ai tests/js/lib tests/js/components/editor tests/js/components/localization` | 1103 tests pass in 74 files |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| `vendor/bin/pint --test` on changed PHP | Passes |
| `grep -rniE "semantic" app resources/js resources/ai routes config` | Only the unrelated word "semantics" (password, key, media handling), `hasSingleReferenceSemantics` and a CSS comment on semantic colors |
| `php artisan docs:generate` | Spec regenerated. Its one remaining match is the asset share "Password semantics" text |

Not verified: the changed review card in a browser, and real-provider runs. The worktree has no running app, so the card is covered by component tests only.
