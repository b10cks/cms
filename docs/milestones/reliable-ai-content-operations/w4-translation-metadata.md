# W4 translation to metadata composition and metadata field coverage

Backend, plan editor and ledger slice of the milestone, not the whole contract. Base commit `772eb2b4`. API and MCP parity is still later. D10 is untouched. No migration: the state fits `ai_operation_items.detail` and `ai_changeset_items.review`, so space migration 000027 stays unused.

## Behavior

**Plan.** A translation plan takes the same optional `metadata` as a general plan: `{keys, overwrite_keys, fields, locales}`. `locales` must be among the plan's own locales, empty means all of them. Stored only when given, so an existing plan keeps its hash. Without it nothing about translation changes.

**One task, in a fixed order.** Each source entry freezes `expected_parts` at approval: the locales, then `<locale>.<field path>.<key>` for each covered field, from the source's document.

1. Translate. Meta keys the plan generates are held from translation work as `generated`, so they are never translated and then overwritten.
2. Read. `get_translation_task` returns `proposal` and `metadata.ready`.
3. Write. `propose_translation_metadata` takes `source_id`, `language`, `proposal_sha256` and `values`.

Code enforces the order. The write is refused until the locale's translation is settled with no open or blocked unit, and refused if the hash is not the text the model read. Once metadata is proposed for a locale, that locale's translation can no longer change in the task, so a recorded dependency cannot go stale inside it. A translation being proposed settles only its locale part, so metadata stays owed and ends `unfinished` if never written.

**Reading a proposal.** `ProposedTranslations` overlays the task's open proposals on the stored row, or reads the document of a pending create. It is addressed by source and locale, because correcting a proposal replaces its item. Language fallback and the source language are never substituted. If a reviewer rejected a proposal behind the text, the keys end `skipped` with `translation_rejected` rather than reading older text. That is about metadata not yet written. Existing dependents are left alone.

**Missing locale rows.** A create holds the whole new entry, and the metadata must not change it. So the metadata is its own `update` item per field, with `target_id` a placeholder for source and locale (`pending-<32 hex>`, see `PendingTranslationTarget`). The recorder's replacement of same-path proposals then works per row. Accepting one before the row exists ends as a conflict, the ordinary result for a missing target. Creating the row binds it, see hook 1.

**Same field, one proposal.** A meta field is one proposal per target. If translation already holds an open item for keys the plan does not generate, such as `seo.ogTitle`, the metadata item replaces it, carrying the translated values unchanged, its `translation_sources` rows and its glossary findings. Rejecting that item rejects both. Dependencies leave out these generated fields, and so does `proposal_sha256`, so writing one key does not change what the next key rests on.

**What metadata keeps.** `MetadataOutputs` as before: only `keys` are writable, a stored value stays unless in `overwrite_keys`, title 60 and description 155 characters per key. The base is the open proposal's field, else the source's field with text blanked, which is how the proposer already starts an empty meta field. Canonical, robots and images stay. Generated text never comes from the source.

**Review.** Semantic review stays on for the new items, under the pinned policy, stated in the real target locale. Deterministic findings cover forbidden wording only: generated metadata is a summary, so requiring every source term would flag everything.

**Field coverage.** `MetadataFields` walks `meta` fields at any depth, for general and translation plans. Parts use the existing path, `<locale>.body.<block id>.seo.title`. A plan names a nested field as `<block slug>.<key>` in `fields`. Empty `fields` now covers nested fields too.

Limitations are explicit parts, `skipped`, which keeps `coverage.complete` false:

| Reason | When |
| --- | --- |
| `no_stable_path` | A block item without an ID. The part reads `body.@1.seo.title`; the position is a label, nothing writes to it |
| `not_stored_on_translation` | A non-translatable meta field on an overlay translation |
| `block_not_in_translation` | The translation lacks the block: a standalone row at freeze, or a proposal without it at read |
| `translation_not_in_plan` | The plan leaves out the work this locale needs |
| `pending_review` | Another changeset holds the translation or the field |
| `translation_rejected` | See above |
| `awaiting_translation` | Held by a blocked translation; an answer to it reopens these |

**Translation exceptions by unit.** `report_items` `outputs` takes `unit` beside `part`. The unit becomes its own part `<locale>#<unit id>`, listed in `detail.unit_parts`, with its outcome, note and question. Grouped questions, answers and coverage pick it up with no change, because they work on parts. Other units continue. The locale settles when each unit is proposed or settled alone, as `proposed`, or `skipped` with `unit_exceptions` if nothing was proposed. A report naming only the locale still works as before. Locale part keys and their stored shape are unchanged.

An answer reopens only that unit. A resumed batch may propose only reopened units, and anything else in the call refuses the call. The metadata held by a blocked unit reopens with it.

**Parent patch adopted.** `AiOperations` carries the exact `answers` context and `retainAnsweredQuestions` patch. The parent's `AiOperationQuestionsTest` was not copied; its required-hook test now runs here instead of skipping. Run the parent's new pending-review test after integrating.

## Decision D10

Still open, recorded as open in the plan UI. The dependency is recorded, and nothing reads it for behavior: no stale marking, invalidation, cascading rejection or acceptance block. A test proves a rejected source creates no action on anything else.

## Parent hooks

**Hook 1, required.** `app/Actions/Ai/AcceptAiChangesetItems.php`, in `create()`, directly after `$item->target_id = $content->id;`:

```php
app(\App\Services\Ai\Translation\PendingTranslationTarget::class)->bind($item, $content);
```

It sets `target_id`, `target_label`, `base_version_id`, `before` from the new row, clears a conflict from an early accept, and adds `review.pending_target.content_id`. It runs in the create's transaction and is idempotent. This is the target coming into existence, with no policy on the source's review. Without it, the metadata of a missing locale stays a conflict after its create is accepted.

I tested with this line applied and reverted it before committing, as briefed: `AiTranslationMetadataTest`, `AiAcceptanceAtomicityTest` and `AiChangesetTest` pass both ways. The test calls `bind` itself, which is safe with the hook in place. An accept-all takes items in ID order, so a create goes before its metadata.

**Hook 2, for the reviewer UI owner.** Files I did not touch. An item with `review.pending_target` and no `content_id` has a `target_id` that is not a row, so don't link it. `before` holds the proposed value. Types:

```ts
review: {
  derived_from?: Array<{ item_id: string; operation?: 'create' | 'update'; target_id: string | null; path: string | null; locale: string | null; source_id?: string; after_sha256: string }>
  derivation?: { source_id: string; source_version_id: string | null; locale: string; state: 'proposed' | 'stored'; translation_version_id: string | null; proposal_sha256: string }
  pending_target?: { source_id: string; locale: string; content_id?: string }
}
```

`state: 'stored'` means the locale needed no translation work, so `derived_from` is empty and `translation_version_id` is the exact dependency. An accept before binding returns the ordinary conflict with `current: null`.

## API contracts, for the later parity task

Additive. No generated spec, MCP registry, SDK or shared locale JSON changed.

- `plan.metadata` on `kind: translation`. `plan.metadata.fields` accepts `<block slug>.<key>` on both kinds.
- `report_items` `outputs[].unit`.
- `get_translation_task` adds `proposal` and, under such a plan, `metadata: {ready, owed: [{part, path, key}], keys, overwrite_keys}`.
- `proposal` is `{state, action, source_id, language, name, version_id, item_ids, rejected, sha256, texts}`.
- `get_entry` adds input `language` and output `proposal`, for a run with open proposals there. `content` stays what is stored.
- `propose_translation_metadata` is added in code to these batches whatever the skill lists, so the skills' tool lists are unchanged.
- Item `parts` may hold `<locale>#<unit id>`, and `<locale>.<path>.<key>` on translations. New reasons are in the table above and `unit_exceptions`.

`detail.unit_parts` is not served: the rows come from `parts`. No resource change is needed.

## UI

`AiOperationMetadataOutputs` gets `linked`, used under "Work to do" in the translation plan: a switch, values, values to replace, fields and the plan's target locales. Nothing is on by default, so an absent plan key stays absent. `locales` is limited to the plan's in the payload. A read-only summary shows the same. When on, one line states that the link is recorded and that what happens on edit or rejection isn't decided, with no toggle.

Meta field options add nestable blocks as "Teaser: SEO". `outputTarget` reads the new part shapes, and the ledger and question rows show them under their language. Copy is in the local `i18n/{en,de}.json`.

## Checks

From this checkout with its own copied `vendor/`, `bun install --frozen-lockfile`, a temporary `APP_KEY` and no `.env`. SQLite and the fake driver only.

```text
vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai
  tests/Feature/Mgmt/AssetClassificationTest.php tests/Feature/Mcp/AiMcpToolsTest.php   OK (407 tests, 2739 assertions)
vendor/bin/pint --test on all touched PHP                                               passed
bunx vitest run tests/js/lib tests/js/components/ai tests/js/composables/useAiRun.test.ts   68 files, 1042 tests passed
bunx vue-tsc --noEmit                                                                   clean
bunx oxlint --deny-warnings on changed .ts and .vue                                     clean
```

New: `AiTranslationMetadataTest` (12 tests), including the end-to-end resumed translation answer through a translation batch. Additions to `aiOperations.test.ts` and `AiOperationPanel.test.ts`. No full build, no paid provider call, no live migration.

## Visual check

Real `AiOperationPanel` and real CSS on a throwaway Vite page with a mocked API, driven by Playwright 1.64 on the cached Chromium. No preview tools. My server, port 5191, was stopped and the fixture deleted. PID 79171 and port 3000 were left alone.

Inspected: switch off (dark 520), on with nested fields (light 390), on in German (dark 520), approved plan (dark 520), ledger (light 520), ledger in German (dark 390). `overflowX` was false and there were no console errors in all six.

Found by looking and fixed: a nested row truncated before its key name at 390 px. Output labels now wrap.

Limits: global copy in the German fixture reads English, because the global catalog isn't loaded there. Not checked: the panel in the real dock, real data, the authenticated app. A nested field reads "Search listing (body 01ja…): Title" because the path holds a block ID, not its name. A unit row shows its unit ID.

## Not verified

MySQL JSON behavior, real providers, a real queue and concurrency across processes. Whether a model follows the order is enforced only as far as the refusals above. Editorial quality of generated metadata and of the German copy was not reviewed.

## Remaining gaps

- **D10.** Open, as above.
- **Reviewer UI** for pending targets and dependency provenance, hook 2. API and MCP parity, generated specs and the SDK.
- **Semantic review's source text.** `initial()` judges relevance from the translated text, but a later review pass reads `SemanticSubjects::sourceOf`, which is `before` for these items. Owner of semantic review should decide whether to pass the derivation text.
- **Frozen at approval.** A block added to the source later owes no parts and the write refuses it.
- **Stored-value staleness.** A generated key already holding a value stays `satisfied` when its translation goes stale, unless the plan may overwrite it.
- **Rejected metadata.** Rejecting a proposal still does not reopen its part, as before.

## Changes outside the assignment

- `SemanticSubjects::locale()` read `SpaceSettings` as an array whenever a target had no row, which crashed any such proposal. One line, needed for pending targets.
- Pint also reordered imports in `RunToolFactory`, the same reorder as the parent's uncommitted patch there.

## Follow-ups noticed

- "1 question on 1 outputs" in the questions header picks the wrong plural. It predates this slice.
