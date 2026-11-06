# W3 semantic review and reasoned overrides

Backend slice on `c0fdd218`. It does not complete the brief. No UI, shared spec, MCP registry, plugin i18n or locale file changed.

## Parent patches adopted

`AiRunService`, `ChangesetRecorder` and `AiOperations` carry the parent's exact patches: `KnowledgeResolver` injected, `review.policy` on proposals, full pinned terms in final checks, the policy pinned and expected outputs frozen at approval. The parent can drop its copies of those three files. `RunToolFactory` is untouched: the parent keeps its `lookup_glossary` patch. With that patch and the parent's `AiPolicySnapshotTest` applied temporarily, that test passes (21 tests, 138 assertions).

## Behavior

### Review pass

A review is an ordinary `AiRun` of the new built-in `semantic-review` skill: no tools, JSON schema, at most 3 provider turns, surface `semantic-review`. It uses the run lease, recovery, provider backoff and cost accounting. It reads the output and evaluates. It writes nothing but review state.

| Scope | When | Budget |
| --- | --- | --- |
| Running operation | Between batches, as `current_run_id`. The operation completes only with no review left to run | `remainingUsd()` |
| Operation not running, edits only | On its own (`out_of_band`), when a reviewer's edit needs a result | `remainingUsd()`. Spend and tokens reach the operation |
| Standalone run, not inline | After the run finishes | `setup.budget.max_usd` minus the run's cost and its earlier reviews |

Reviews of one policy go in one run, up to `ai.semantic_review.max_subjects` (10) and `max_chars` (40,000). A review run pins the proposal's snapshot through `StartRun::$policySnapshotId`, so a newer live policy cannot enter.

### What gets reviewed

Knowledge is relevant when it is frozen in the snapshot and either:

- a document rule of the target or source locale, or
- a term the source or output activates, directly or by alias, that needs interpretation: status `contextual`, applicability, a note, or knowledge `notes`, `rules`, `qualifiers` or `unknown_fields`. The linked target-locale term counts too.

Zero relevant knowledge settles as `not_applicable`, with `reason: no_contextual_knowledge`, without a provider call. Audience and product come from the text: the model reports them and its certainty. Nothing is configured.

Skipped on purpose: asset folder, tag and move proposals and block proposals (`not_applicable`, `not_copy`), inline editor proposals (`not_reviewed`, `inline_editor`).

### Exact memory

- A proposal holding memory text is reviewed like any other. Nothing skips because the text matched.
- A translate run whose output memory filled, including a full hit with no provider call, gets `result.semantic_review` when `setup.translation_memory.unverified` names terms. `unverified_sources` was added to `setup.translation_memory` because the context no longer holds those sources. The review result lands on the run, not on an item, because that output goes to the open editor or the caller.

### When a review cannot finish

| Situation | Result |
| --- | --- |
| Transient provider error | The review run backs off with `ProviderRetry`. State stays `pending`, the operation keeps running |
| Spent budget, access lost or a cancelled run, in a running operation | `pending`, the operation pauses. A resume or budget extension retries. After `max_attempts` (3) it becomes `limited` |
| Same stop outside a running operation, or budget spent before starting | `limited` (`budget_exhausted`, `max_usd`, `cancelled`, `access_revoked`), no provider call when nothing is left |
| Exhausted or permanent provider error | `limited` (`provider_unavailable`, `provider_error`), the operation goes on with other work |
| Unparseable or schema-invalid after corrections, step, token or time limit | `limited` (`malformed_output`, or the limit's name) |
| No result for a subject | `limited` (`missing_result`) |
| A rule the subject was given not listed in `checked` | `limited` (`incomplete_review`) |
| Finding with an unknown rule, or a quote not in the text | `limited` (`unattributed_finding`). The raw claim is kept |
| `certainty: uncertain` with no finding | `limited` (`uncertain_context`) |
| Text plus rules over `max_chars` | `limited` (`too_large`), not reviewed in part |
| Operation cancelled | `limited` (`operation_cancelled`) |

### Acceptance

Each runs under the item locks, in the existing per-target transaction. A rollback undoes it with the decision.

- Bulk accept leaves out anything not `clean` or `not_applicable`, and any item with `review.semantic_edit`. Flags go in the existing `flags` column, so `needsIndividualLook` and group counts see them.
- A flagged or limited item can be accepted individually. That decision is the human review. So can a `pending` one: its review still runs and reports afterwards.
- Deterministic findings of the text being applied need an override with a nonempty reason. This applies to all items with findings, legacy ones too, except `selection`, which the open editor has already applied.
- An edit is rechecked deterministically against the proposal's frozen terms (legacy: its run's `setup.glossary.terms`). A semantic result must exist for that exact text (`output_sha256`). The first accept stores `review.semantic_edit`, leaves the item open and starts a recheck. The result of the proposed text stays with that text.
- D10 is untouched.

## Contracts for UI, API and MCP

**Accept request** adds `overrides`:

```jsonc
{ "item_ids": ["…"], "values": {"<item>": "edited"},
  "overrides": {"<item>": [{"finding_id": "<id>", "reason": "Why it stays"}]} }
```

**Accept response** adds `rechecking: n` only when above 0. Other keys are unchanged.

**Missing override:** 422, `errors["overrides.<item>.<finding id>"]`, with nothing applied to that entry. An edit's new findings are only named this way, so the UI reads the finding ID from the key. As with other 422s, entries decided earlier in that request stay decided.

**The accept flow for an edit:** post values, read `rechecking` and `review.semantic_edit`, wait for a non-`pending` status (`AiRunEventsAvailable` for the actor and the thread broadcast), show the result, post the same values again. A budget that cannot pay shows `limited` right away. The second post applies it.

**Item resource:** `glossary_findings[].id` is new. `review` already reaches clients and now holds:

```jsonc
"semantic": {
  "status": "pending|clean|flagged|limited|not_applicable|not_reviewed",
  "reason": "no_contextual_knowledge",     // not_applicable and not_reviewed
  "policy_snapshot_id": "…", "fingerprint": "…", "output_sha256": "…",
  "source_locale": "de", "target_locale": "en", "attempts": 0,
  "findings": [{ "kind": "violation|uncertain", "rule_id": "term:…|rule:…",
                 "rule": { "type": "term|rule", "id": "…", "term": "…", "note": "…", "path": [], "text": "…",
                           "locale": "de", "concept_id": null, "revision_id": null, "version": "…", "status": "contextual" },
                 "quote": "…", "explanation": "…" }],
  "limitations": [{ "code": "…", "message": "…" }],
  "context": { "audience": "…", "product": "…", "certainty": "certain|uncertain", "evidence": "…" },
  "checked": ["term:…"], "run_id": "…", "reviewed_at": "…"
},
"semantic_edit": { /* same, plus */ "value": "…", "edited_by": "…", "edited_at": "…" },
"overrides": [{ "finding_id": "…", "finding": {}, "reason": "…", "text_sha256": "…",
                "policy_snapshot_id": "…", "reviewer_id": "…", "overridden_at": "…" }]
```

Rule IDs are the snapshot's: `rule:<glossary>:<source>:<locale>:<rule key>`. `flags` gains codes `semantic_violation`, `semantic_uncertain` and `semantic_review_limited`, with extra keys `rule_id`, `quote` or `reason`. A proposal without `review.semantic` predates this work: show it as not reviewed. Do not call it clean.

**Run result:** `result.semantic_review` has the same state plus `units` and `unverified`.

**Operation:** `counts.semantic_review` holds counts by status, stored at completion, pause and cancel. It is a separate number from `counts.coverage`. A completed operation with full coverage can still hold flagged or limited proposals. Stored counts are not updated by later edit rechecks. While a review runs, `current_run_id` is a run with `setup.operation.phase: review`, so a UI calling it a batch needs adjusting. `batch_count` does not change.

**Skill parser:** the surface pattern accepts `semantic-review`. `RunRecovery::sweep()` adds `reviews`.

**Needed before this ships to editors:** the current review UI sends no `overrides`, so individually accepting an item with a glossary finding answers 422 until the UI delegate adds it.

## Migration and deployment

None. Space migration 000025 is unused. State fits `ai_changeset_items.review`, `flags`, `ai_runs.setup` and `result`, and `ai_operations.counts`. Pending lookups scan JSON within one changeset. The sweep looks only at changesets and runs active in the last 2 days. Existing proposals gain no state and start no paid work. Operations approved before this deploy and still running review their new proposals from their remaining budget.

## Checks

SQLite, fake driver, PHP 8.5.12, PHPUnit 13.4.5, copied `vendor/`, throwaway `APP_KEY`. No paid provider.

```text
vendor/bin/phpunit tests/Feature/Ai/AiSemanticReviewTest.php            OK (18 tests)
vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai \
  tests/Feature/Mgmt/AssetClassificationTest.php tests/Feature/Mcp/AiMcpToolsTest.php  OK (384 tests, 2,413 assertions)
vendor/bin/phpunit --filter 'Repair|SpaceMigrat|SkillParser' tests      OK (26 tests, 91 assertions)
vendor/bin/pint --test on all touched PHP                               passed
```

No existing test changed. Disabling each of these made focused tests fail: the bulk exclusion, quote verification, the edit recheck, the operation's review step, the override reason. Pinning the snapshot on a review run survived that first round, because operations resolve the same snapshot anyway, so the standalone test now changes knowledge before its review.

## Remaining limits

- **Not verified:** MySQL JSON paths in pending queries, row locks and multi-process races on these paths, a real worker kill, real provider output. Quality of the semantic judgment is not assessed, by agreement.
- **Inline editor output is not reviewed,** except exact memory with unverified terms. Selection rewrites and provider-written editor translations apply in the open editor as the user watches, so they record `not_reviewed`. A review would double their cost and arrive after the decision. Worth a decision if they should be covered anyway.
- **Selection accepts need no override** for the same reason: the editor already applied them.
- **Reference and style documents are not in the packet.** Only published document rules and terms are judged, since those have stable rule identity. Reference chunks are retrievable by the generating run only.
- **Findings on memory output in the editor** sit on `result.semantic_review`. Nothing presents them yet, and the items `recordTranslations` wrote stay `not_reviewed`.
- A `limited` review is not retried by itself. A new edit, or an API added later, starts another.
- A review that fails mid-operation does not pause it. A long provider outage therefore ends in limitations on that run's subjects, then the next batch pauses on the same outage.
- A review can overspend its limit by one call, like every run: the budget guard checks between calls.
- The bulk path keeps its existing accept-all with `values`: an edit without findings or relevant knowledge applies, one with findings stays open, one with knowledge starts a recheck.
- An edit's override is not stored until its decision commits, so a re-post must send it again.
- Reject does not consult review state, and a rejected item's pending review is dropped.
- Later wave: API spec, MCP registry and receipts must expose the contracts above.
