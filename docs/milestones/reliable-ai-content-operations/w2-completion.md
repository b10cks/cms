# W2 field completion and metadata

Backend slice of the milestone, not the whole contract. Base commit `a9642356`.
No migration: the state fits `ai_operation_items.detail` and `ai_operations.counts`.
Space migration 000023 stays unused.

## Behavior

**Output requirements.** An operation entry can owe parts. Triage freezes them once in
`detail.expected_parts`, the first time the entry is about to join a batch, from the
approved plan and the real rules. Nothing is read from prose.

| Operation | Parts | Comes from |
| --- | --- | --- |
| Translation | `<locale>` as before, unit detail in `detail.units[<locale>]` | `plan.locales` |
| Assets with `outputs` | `<language>.<field>`, `tags`, `folder` when the plan may move | `plan.outputs`, `plan.languages`, folder AI field rules |
| General with `metadata` | `<locale>.<meta field>.<key>` | `plan.metadata`, the entry's block schema and language |
| Any other plan | none, one piece as before | coverage says `verified: false` |

**Outcomes per part**, in `detail.parts[<part>]` as `{outcome, reason?, note?, question?, batch?}`:
`proposed`, `satisfied`, `intentionally_empty`, `uninferable`, `refused`, `blocked`, `invalid`,
`unfinished`, plus `skipped`, `unchanged` and `failed` as they were. `invalid` stays owed: a later
attempt can fix it, and it survives as the final outcome when attempts run out. Anything still
open then becomes `unfinished` and the entry fails as `not_processed`, its proposals kept.

**Rules enforced in code.**

- `ChangesetRecorder` no longer marks an entry worked in parts as proposed. Tools report each
  part they covered through `ReportsOutcomes`. A crash repeats the call, and accounting is
  idempotent.
- A claim about a whole entry settles no owed part. `unchanged` settles only open parts that
  hold a value the plan could replace (`detail.overwritable`, shown to the model as `may_keep`).
  A run's `skipped` keeps its note and settles nothing. A skip reason from code (an unreadable
  file) still covers open parts. This also applies to translation locales, which is a behavior
  change: `report_items` for a whole translation entry used to settle every open locale.
- `report_items` gains `outputs: [{part, outcome, reason, question?}]`. `satisfied` holds only
  for a part in `may_keep`. `blocked` needs the question.
- Per-field asset handling under `outputs`: valid fields are proposed, invalid or unrequested
  fields come back as `invalid`, tags and placement count on their own. Image evidence still
  gates the whole entry and `review.evidence` is unchanged. A proposal no longer discards the
  entry's other pending parts. A part proposed by an earlier batch stays: a retry naming it is
  ignored, and the batch that proposed it may correct it.
- Asset operations without `outputs` keep the old behavior, including replacing the asset's
  pending proposals.
- Provider retry, backoff, pause, budget, access and spend rules are untouched. A no-fault stop
  costs no attempt and closes no part.

**Metadata.** `propose_update` merges any proposal for a `meta` field over the stored object and
this run's open proposal ([`MetadataOutputs`](../../../app/Services/Ai/Metadata/MetadataOutputs.php)),
so canonical, robots, ogImage and other unnamed keys stay. This holds for every run, not just
operations. Title 60 and description 155 characters are checked at this boundary; manual CMS
writes are unaffected.

- Under a `metadata` plan: only `keys` are writable, a stored value is kept unless its key is in
  `overwrite_keys`, other paths on the entry are refused, and each key is accounted for alone.
- In any other operation batch: the four generated keys, fill-empty only, no field accounting.
- In a reviewed run outside an operation: any key may be set or cleared. Limits still apply.
- Skips are visible: `no_meta_field`, `locale_not_in_plan`, and per key `pending_review` when
  another changeset has an open proposal for that meta field.
- `MetaBlock.vue` already spreads the stored value when it applies a `page` answer, so the editor
  button keeps unrelated keys too. It was read, not changed.
- `meta-tags/schema.json` adds `maxLength`, and `OutputSchema` now enforces `maxLength`, so the
  `page` JSON answer goes through the existing correction turn.

**Dependency record.** `propose_update` takes `derived_from` (changeset item IDs of this task for
this entry) and stores `review.derived_from: [{item_id, target_id, path, locale, after_sha256}]`.
Nothing reads it. Acceptance and rejection are unchanged: D10 remains open, and a test proves a
rejected source does not affect the dependent proposal.

**Blocked fields.** `OutputCoverage::blocked($operation)` lists
`{item_id, target_type, target_id, part, question, note}`.
`AiOperationItem::reopenBlocked($parts)` makes those parts owed again and requeues the entry with
fresh attempts, leaving other parts settled. The later question task still has to restart a
finished operation; that path does not exist yet.

## Contract for the UI and API owner

Plan, all additive. Each key is stored only when given, so existing plans keep their hash.

```jsonc
// kind: assets
"outputs": { "fields": ["alt", "title"], "tags": true }
// kind: general, content scope
"metadata": { "keys": ["title", "description"], "overwrite_keys": [], "fields": [], "locales": [] }
```

Removing or planning tags requires `outputs.tags` once `outputs` is present. `fields` and `locales`
are validated against the scope's block schemas and the space languages. Empty means all.

`counts.coverage`, written when an operation completes, pauses or is cancelled. It already reaches
clients through `AiOperationResource.counts`:

```jsonc
{ "verified": true, "complete": false,
  "outputs": { "proposed": 3, "satisfied": 1, "unfinished": 1 },
  "entries": { "proposed": 1, "failed": 1 } }
```

`verified: false` with `complete: null` means the plan named no outputs. Read `complete`, not
`state`: `completed` means no entry is still queued, and it can hold exceptions.

Translation `unchanged` is counted as `satisfied` here. `complete` is false for any exception,
including an entry skipped as unsupported, deleted or `pending_review`.

`AiOperationItemResource.parts` already serves the new outcomes, keyed as above. The extra keys
`note`, `question` and `batch` arrive through it too. Non-translation parts are not locales, so a
UI that labels `parts` as locales needs adjusting.

Batch context entries gain `owed` and `may_keep`. Item proposal results gain `outputs` (assets) and
`metadata` (content), and the `propose_plan` schema gains `outputs`, `metadata`,
`overwrite_fields` and `languages`.

## Integration requests

1. `app/Http/Resources/Management/Ai/AiOperationItemResource.php`, after `'parts'`:

   ```php
   // Parts this entry owes, frozen from the approved plan; null when it is one piece.
   'expected_parts' => $this->detail['expected_parts'] ?? null,
   // Open parts holding a value the plan may replace.
   'overwritable' => $this->detail['overwritable'] ?? [],
   // Units proposed and remaining, by locale of a translation.
   'units' => (object) ($this->detail['units'] ?? []),
   ```

2. `resources/js/lib/aiOperations.ts:188`: widen the part type with
   `note?: string; question?: string; batch?: number`, add the three fields above and
   `counts.coverage`, and add the outcomes to any labels and `en`/`de` locales.
3. Plan editor UI: send `outputs` or `metadata`. If it rebuilds plans from known keys it will
   strip them, and the operation then reports `verified: false`.
4. `AiOperationResource` could show live coverage by calling `OutputCoverage::of()`. It is not
   stored per batch because it scans the ledger. Not needed for the stored summary.
5. Generated API specs and the MCP registry need the plan and report shapes.
6. `resources/ai/skills/asset-organization/SKILL.md` was outside this assignment and does not
   mention `owed` or `outputs`. The batch instructions and tool descriptions do. Worth one
   paragraph, as `meta-tags` got.

No change is needed in `ContextBuilder`, `AiRunService`, `BedrockDriver` or `AiRunResource`, or in
the acceptance files. `config/ai.php` gains one line registering `MetadataTriage` for `general`, and
`OutputSchema` was edited although neither was named in the assignment.

## Checks

From this checkout with its own copied `vendor/`, a temporary `APP_KEY` and no `.env`. SQLite and
the fake driver only.

```text
vendor/bin/phpunit tests/Feature/Ai                               OK (215 tests, 1378 assertions)
vendor/bin/phpunit tests/Unit/Services/Ai                         OK (65 tests, 153 assertions)
vendor/bin/phpunit tests/Feature/Mgmt/AssetClassificationTest.php OK (28 tests, 135 assertions)
vendor/bin/phpunit tests/Feature/Mcp/AiMcpToolsTest.php           OK (4 tests, 31 assertions)
vendor/bin/pint on all touched PHP                                passed
```

New: `AiOperationCompletionTest` (10 tests) and `AiMetadataOperationsTest` (9 tests). No existing
test needed changing.

## Migration and deployment

None. Operations already approved keep their plans and report `verified: false`. In-flight
translation operations gain the stricter entry-level rule from their next tool call. `MetadataOutputs`
also starts applying to in-flight general batches writing meta fields.

## Gaps

- **Composition is not wired.** One approved task cannot yet generate metadata from translations it
  proposes. A translation plan has no `metadata` key, a new locale row does not exist until its
  create proposal is accepted so metadata has nothing to target, and `get_entry` shows stored values
  rather than the open proposal. `derived_from` records the identity once that exists. This needs a
  contract for targeting a pending create, plus the D10 decision.
- **Grouped questions.** Only the storage and reopen interface exists. Batches still drop
  `ask_questions`.
- **Nested meta fields.** Requirements cover root `meta` fields only. A nested one is merged safely
  by `propose_update`, but a metadata plan refuses it. Requirements for a block's meta field would
  need stable block IDs in the part.
- **Field-level exceptions in translation.** Reports work per locale. `detail.units` shows which
  units were proposed and remain, but an exception names the locale, with the field in its note.
- **Frozen at claim, not approval.** Requirements read the schema and folder rules when an entry first
  joins a batch. A schema change between approval and that moment shows in the parts. Freezing 10,000
  entries at approval would need the same reads up front, and I saw no need.
- **Coverage after a review decision.** Rejecting a proposal does not reopen its part. `proposed`
  means a proposal was made, not that it was accepted, in line with the brief's distinction.

## Not verified

MySQL JSON behavior, real providers, real image-model judgment and multiprocess concurrency. Editorial
quality of generated metadata was not assessed.
