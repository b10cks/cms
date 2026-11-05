# W3 policy snapshots evidence

Base commit: `3bedc9b0`. This slice covers snapshots, published document rules, language selection and frozen terminology for memory. Semantic review, impact discovery and receipts are not done, and the full knowledge scope is not complete.

Owned paths changed: `Run/ContextBuilder`, `Knowledge/KnowledgeResolver`, `PolicySnapshot` (new), `GlossaryChecker`, `GlossaryMatcher`, `GlossaryTerm`, `Knowledge/Import/DocumentRuleDiff` (new), `Tools/LookupGlossaryTool`, `Tools/GetTranslationTaskTool`, `Translation/TranslationMemory`, `Translation/TranslationProposer`, `Actions/Ai/Glossary/PublishGlossaryImport`, glossary and knowledge models, requests and resources, migrations `2026_11_02_110000` and `000024`, `tests/Feature/Ai/AiPolicySnapshotTest.php`.

Small edits outside the list, each needed for the behavior: `CreateGlossaryImportDraft` (rule diff decides open or closed), `AiGlossaryImportController` (passes new publish fields), `AiKnowledgeDocumentController` (accepts `locale`), `ProposesTranslations` (passes the run to the proposer), `AiOperation` (docblock line).

The parent's patches are in this branch: exact-locale wording in `ContextBuilder`, and the three `GlossaryChecker` behaviors. They can be dropped from the parent when this merges.

## Behavior

### Snapshot

`PolicySnapshot` holds resolved terms including manual edits, glossary heads, style and reference revisions, and published document rules. The fingerprint is a SHA-256 of canonical JSON with sorted object keys and sorted rows, so MySQL JSON key order and row order do not change it. Timestamps are not part of it.

Rows are stored once per `(space_id, fingerprint)` in `ai_policy_snapshots` and refuse update and delete. A run stores only a reference, so `setup` stays small and an unchanged policy reuses one row. Rows are never pruned.

Style guide text is stored in the payload, because it enters prompts whole and a deleted document must not change an approved task. Reference documents are stored by revision. Their chunks stay in the management database, so deleting a reference document does remove it from a running task.

| Caller | Policy |
| --- | --- |
| Standalone run | Captured at start in `ContextBuilder`, stored as `setup.policy` |
| Operation batch | `ai_operations.policy_snapshot_id`, set once at approval. Pause, resume, retry and budget extension never recapture |
| Operation planning run | Its own capture at start |
| Operation approved before snapshots | Captures per run, which is the same freshness those batches had before |
| Run from before snapshots (`setup.policy` absent) | Reads the live state, as before. `isPinned()` is false |
| MCP, no run | Reads the live state, unstored |
| Snapshot `version` newer than the code | Throws. A missing row also throws, instead of falling back to live |

`setup.policy` shape: `{ id, fingerprint, version, rules: [rule ids in the prompt], rules_omitted: int }`.

### One policy per run

`ContextBuilder`, `get_translation_task`, `TranslationProposer` and memory all read the run's snapshot. `setup.styles`, `setup.knowledge` and `setup.glossary` are derived from it, so `search_knowledge` and `read_knowledge` follow too. `setup.glossary.terms` keeps its shape without `knowledge` text. `lookup_glossary` needs the factory hook below.

### Retrieval bounds

- The snapshot keeps full `knowledge`. Prompts carry term notes up to 500 characters.
- Document rules in a prompt or tool result are capped at 3,000 characters and 1,000 per rule, those sharing the most words with the text first. `document_rules_omitted` or a prompt line reports the rest.
- Rules for the writing and source languages are candidates. Rules in other languages stay out.

### Locale matching

- Term source: exact locale, then base language. An exact term shadows its base for the same concept or wording. A reviewed term from a sibling region does not apply, and a region does not serve its base.
- A term with no status (`status: null`) keeps its older per-language match, ranked below both. This preserves existing glossaries.
- Documents and rules: for each locale, those of exactly that locale, else of its base language. A null `locale` always applies, so existing documents do not change.
- Writing locales: an approved plan's `locales`, or `context.operation.plan.locales`. A run with `targets` and no plan locales, or a translation plan with null locales, keeps every guide, since its languages are unknown. Otherwise the run's target.
- A chat turn that works in another language gets that language's guide from `get_translation_task` as `style_guides`, up to 8,000 characters each.

### Aliases

Source aliases activate a term in `activate()`, `lookup_glossary` and `GlossaryChecker`. A protected brand misspelled in the source now requires the correct name in the output. Aliases stay out of `forbidden` and `translations`. `GlossaryMatcher::match` still defaults to `withAliases: false`.

This reverses a W2 note that deterministic checks leave aliases off, as this assignment requires. Two concepts sharing an alias both activate and both produce findings. Findings only flag, so a reviewer sees both.

### Applicability

Labels and conditions go to the agent as data, with the instruction to judge from content, follow when unsure and flag uncertainty. Nothing filters on them. `activate()` also returns the same concept's term in the target locale, because editors write notes per language. No configured mapping is involved. Whether the agent judged correctly is untested and belongs to semantic review.

### Document rules

`ai_glossary_document_rules` holds published rules per glossary, source, locale and rule key. The key hashes the folded heading path and its occurrence. Revisions record each change in `document_rules` with before and after text.

| Situation | Result |
| --- | --- |
| Publish without `document_rule_keys`, partial or not | Every added or changed rule publishes, attributed to that revision |
| `document_rule_keys: []` or a subset | The rest stay unresolved in the draft |
| Later partial publish | Unchanged rule keeps its row and `revision_id` |
| New source text | `changed`. The published rule stays in force until published |
| Section dropped from the source | `removed`. Stays in force until `confirm_document_rule_removals` names it. Naming it in `document_rule_keys` is a 422 |
| `dismiss_document_rule_keys` | Declines this exact change. Published state stays. Carries over to a superseding draft |
| Any rule unresolved | The draft stays open, even when all entries are settled |
| Same content re-uploaded after an older closed draft with unpublished sections | New open draft. This is how sections from pre-migration publications go live |

### Additive API

- Publish request: `document_rule_keys`, `confirm_document_rule_removals`, `dismiss_document_rule_keys`. `entry_ids` may be empty or absent when one of these is sent. Nothing to do at all is a 422 on `entry_ids`.
- Draft resource: `document_rules: [{ rule_key, kind, path, text, published_text, line, fingerprint, revision_id, dismissed }]`. `document_sections` is unchanged.
- Revision resource: `document_rule_summary` always, `document_rules` on show and publish.
- Knowledge documents: optional `locale`, normalized to lower case with hyphens, on create, update and resource.
- `lookup_glossary`: terms may add `status`, `applicability`, `concept_id`, `revision_id`, `target_term`. Results may add `document_rules`, `document_rules_omitted`.
- `get_translation_task`: units may add `memory_rejected`, `memory_unverified`. Results may add `document_rules`, `document_rules_omitted`, `style_guides`.
- Translation proposal items: `review.policy: { snapshot_id, fingerprint, terms: [{ id, concept_id, revision_id, status, version }] }`, only for pinned runs. Bulk acceptance does not read it.
- Run setup: `translation_memory` may add `rejected`, `unverified`.

### Translation memory

`exactUnderPolicy` returns `{ hits, rejected, unverified }`. Rows for a hash are checked newest first and the first compliant one wins, so a failing new pair does not hide a passing older one. No compliant row means no hit, and the findings are reported. Human wording that passes is kept. `fuzzy(terms:)` drops non-compliant examples.

`unverified` names contextual or conditioned terms the source activates. A hit there passes string checks only. It is not proof of contextual or factual fit, and a full exact hit still skips the provider, so semantic review must read `setup.translation_memory.unverified`.

`exact()` and `fuzzy()` without terms are unchanged. `translation_units` gains no column: validity is judged at read time, so a policy change takes effect without a backfill and a pair becomes usable again if the term reverts.

### Impact discovery contract

No UI, model pass or paid work is included.

- `$after->changesSince($before)` returns `added`, `changed`, `removed`, each with `type` (`term`, `rule`, `document`), `id`, `version`, plus `concept_id`, `locale`, `revision_id` where they apply.
- `identities()` gives the same data for one snapshot.
- `fingerprint` answers whether anything changed.
- `activate($sourceText, $locale)` tells whether a text touches a changed term.

## Integration requests

These are for files other assignments own. I tested the first two and the test patch together on a temporary copy, then reverted them. See Checks.

1. `app/Services/Ai/Operations/AiOperations.php`, `approve()`, directly before the `forceFill` of `approved_plan`:

   ```php
   app(KnowledgeResolver::class)->pinOperationPolicy($operation);
   ```

   It sets the attribute only. The existing `->save()` writes it with the plan. Until this lands, batches capture per run, so an operation is not yet frozen.

2. `app/Services/Ai/Run/RunToolFactory.php`:

   ```php
   'lookup_glossary' => new LookupGlossaryTool(fn () => $this->knowledge->policy($space, $run)),
   ```

   Until this lands, runtime `lookup_glossary` reads live terms and returns no rules. `get_translation_task` is already pinned.

3. Parent's new `AiSkillResolutionTest` case: `ContextBuilder` now reads space tables, so add `use SpaceTestingTrait;` and `$this->setUpSpaceTesting($space);` before creating the glossary. The new `GlossaryCheckerTest` cases pass unchanged.

4. Provenance on non-translation items, in `ChangesetRecorder` or `ProposeUpdateTool`: merge `review.policy` as `TranslationProposer::persist` does, from `$knowledge->policy($space, $run)->provenance($activatedTerms)`. Metadata proposals otherwise show their policy only through `run.setup.policy`.

5. Receipts: expose `ai_operations.policy_snapshot_id` and `setup.policy` on the operation and run resources. Not added here because the parent has uncommitted edits in those resources.

No `AiRunService` or `StartRun` change is needed. `ContextBuilder` reads `$start->operation['id']`, and `setup.policy` travels through `$built['frozen']`.

## Deploy

1. Management migration `2026_11_02_110000` runs in the normal deploy. It is additive.
2. Space migration `000024` must run through `spaces:repair-databases` **before the code takes traffic**. Every run start now reads `ai_policy_snapshots`, so a space without it cannot start AI runs. Hosted deploys do not run space migrations on their own.
3. No backfill. Sections from earlier publications go live when their source is re-uploaded and published.

## Checks

SQLite in memory, PHP 8.5.12, PHPUnit 13.4.5, fake driver, no paid calls. `vendor/` copied into the worktree, throwaway `APP_KEY` in the environment.

- `tests/Feature/Ai/AiPolicySnapshotTest.php`: 19 tests, 133 assertions.
- `tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp`: 335 tests, 3,490 assertions, 1 skipped.
- Repair, upgrade, classification and migrator-related tests: 67 tests, 254 assertions.
- With hooks 1 to 3 applied temporarily: `AiSkillResolutionTest`, `AiPolicySnapshotTest`, `AiOperationTest`, `AiTranslationOperationsTest`, `AiMcpToolsTest`, `AiAssetOperationsTest`, `AiRunRecoveryTest`: 95 tests, 719 assertions pass.
- Parent's `GlossaryCheckerTest` and `AiSkillResolutionTest` on this branch: checker cases pass. The one resolution case fails only on the missing space tables, request 3.
- Disabling each of these made at least one policy test fail: reading the operation's snapshot, reading the run's snapshot, checker aliases, exact memory check, fuzzy memory check, removal never implied, exact locale ranking above base.
- Migrate, roll back one step and migrate on a temporary SQLite file: pass.
- `vendor/bin/pint --test` on all changed PHP: passes.

## Remaining gaps and limits

- **Not verified on MySQL.** That includes `longText` payload size against `max_allowed_packet`, the management read transaction giving a consistent view under repeatable read, and true concurrent capture. The unique-violation path was not exercised. On Postgres a violation inside an open transaction would abort it; `approve()` holds a cache lock rather than a transaction, so this does not apply there today.
- **No multi-process test.** Batches run inline in tests.
- Not built: semantic review, impact candidate discovery, receipts, UI, API specs, MCP registry. D10 is untouched: no translation-to-metadata dependency behavior was chosen or added here.
- A full exact memory hit skips the provider, so nothing currently judges its `unverified` terms. Semantic review must cover it.
- Checks only cover what `GlossaryChecker` can decide. Rule text is delivered, never enforced.
- `AiRunService::glossaryFindings` still reads `setup.glossary.terms`, capped at 500 (matched terms first). Forbidden terms beyond that are not checked at run level. Per-unit translation findings use the full snapshot.
- Prompt-level glossary matching in a multi-locale batch still uses the run's default locales. Per-locale policy reaches the agent through `get_translation_task`.
- Rule keys depend on the heading path, so a renamed heading reads as removed plus added. Dismissals carry only to a superseding draft. After a draft closes, a declined removal is proposed again by the next upload.
- No endpoint lists live document rules on their own. They are visible through drafts, revisions and snapshots. Adding one needs a route in `routes/private_mgmt.php`.
- Snapshot rows are never pruned, and each holds its own copy of style text. A manual edit makes a new row only when a later run starts.
