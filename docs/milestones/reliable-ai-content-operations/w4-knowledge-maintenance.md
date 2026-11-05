# W4 knowledge maintenance evidence

Base commit: `360a9e36`. This slice covers discovery of content a published knowledge correction may affect, the reviewed step to a draft operation, and retention of reference text for pinned runs. It does not finish the brief. Declined-rule audit, remap, discard, reviewer search names, linked translation and metadata generation and D10 are not part of it.

Owned paths: `Knowledge/Impact/*` (new), `Knowledge/KnowledgeSearch`, `Knowledge/KnowledgeResolver`, `Knowledge/PolicySnapshot`, `Tools/SearchKnowledgeTool`, `Tools/ReadKnowledgeTool`, `Jobs/ScanKnowledgeImpactJob`, `AiKnowledgeImpactController`, requests, resources and models of the same name, `AiKnowledgeDocument`, `AiKnowledgeRevision`, `AiKnowledgeDocumentController::destroy`, `routes/ai_knowledge_impact.php` (new), `resources/ai/skills/knowledge-maintenance`, migrations `2026_11_02_120000` and `000026`, `components/ai-settings/knowledge-impact/*`, `useKnowledgeImpact`, `api/resources/ai-knowledge-impact.ts`, `AiKnowledgeImpactTest`, `AiKnowledgeRetentionTest`, `KnowledgeImpactPanel.test.ts`.

Small additive edits: `api/index.ts` (one getter), `pages/[space]/settings/ai-knowledge.vue` (mounts the panel), `GlossaryRevisionsPanel.vue` (link for space owners).

Untouched as asked: `AiRunService`, `ChangesetRecorder`, acceptance actions, `AiOperations`, `RunToolFactory`, `ContextBuilder`, `routes/private_mgmt.php`, the operation panel, `useAiOperations`, `aiOperations.ts`, shared locale JSON, `plugins/i18n.ts`, generated spec, MCP registry, translation runtime.

## Integration hooks

1. `routes/private_mgmt.php`, inside the `spaces/{space}` group (for example next to the `ai/operations` routes):

   ```php
   require __DIR__.'/ai_knowledge_impact.php';
   ```

   Until this lands the API and panel answer 404. Tests register the same file under `api`, `auth:sanctum`, `space.member`.

2. `app/Services/Ai/Run/RunToolFactory.php::build()`:

   ```php
   $knowledge = $setup['knowledge'] ?? [];
   $sources = fn () => $this->knowledge->referenceSources($run);
   // ...
   'search_knowledge' => new SearchKnowledgeTool($this->search, $knowledge, $sources),
   'read_knowledge' => new ReadKnowledgeTool($knowledge, $sources),
   ```

   Tested on the real factory with a temporary patch, then reverted: a pinned run still answered with the frozen title and slug after a rename and a deletion. Until this lands, text is retained and revision-pinned, but tools name the document by its current row (trashed included), so a renamed document shows the new name.

No other hook is needed. `ContextBuilder` is unchanged, and the parent's `pinOperationPolicy` hook pins the draft's policy at approval as for any operation.

## Reference retention

Chosen: retained revisions by soft-deleting the document. The payload alternative would copy every reference document into every snapshot row and load it on every run.

- `ai_knowledge_documents.deleted_at`. Deleting through the API hides the document from all normal queries, frees its slug, clears its draft and source file, and keeps revisions and chunks.
- `KnowledgeSearch` no longer joins the live document for a pinned run: `search(..., $sources)` takes frozen descriptors. Text always comes from the immutable revision by ID.
- `PolicySnapshot::referenceSources()` returns `revision_id => {document_id, slug, title, locale, revision, hash}`. `KnowledgeResolver::referenceSources($run)` returns it for pinned runs and null otherwise.
- `read_knowledge` finds a slug through the frozen descriptors, so a new document taking the old slug is not read by accident.

| Case | Behavior |
| --- | --- |
| Pinned run, document renamed, re-published, deleted, or slug reused | Same text, same frozen title, slug and revision in sources |
| Run without `setup.policy` | Its own revisions from `setup.knowledge`, named by the current row, trashed included. As before, plus survives deletion |
| Revision really gone (deleted before this migration, owning team or space removed) | `search_knowledge` returns `unavailable: [{document_id, revision_id, title}]` and a note; `read_knowledge` throws a `ToolError` naming it. Never an empty success and never live text |
| New work | Live policy excludes the document; it shows as `removed` to discovery |

Retention ends when the owning space or team is removed, through the existing cascades. There is no purge for a single deleted document. Snapshots already keep style guide text forever, so this matches, but a "really delete this" action is a follow-up if needed.

## Discovery

`KnowledgeImpactScanner` walks `contents` by ID in slices of 100, one job per slice, with candidates and cursor in one transaction. A repeated slice stores nothing twice through `unique(scan_id, content_id)`. Failure sets `failed`; a running scan with no write for 120 seconds reports `stale`. Both continue through `resume` from the cursor. There is no automatic recovery sweep. Limit: 10,000 candidates, then `limited: true` with the unread count visible. A limited scan is not used as a baseline.

The baseline is what the last unlimited completed scan read, else the newest stored snapshot that differs from now. With no snapshot at all, or `mode: full`, there is no comparison and every term counts as added. That is the path for manual and pre-feature content. A full audit lists check results and old wording only. A mention of a term alone would list most of a space. Rules and documents are `unscoped` in that mode.

Publishing starts nothing. The panel shows pending changes on load (`GET ai/knowledge-impact`, which stores nothing) and the editor starts the scan. I read "identifies" this way because a team publication reaches many spaces and the scan is per space.

### Classes

| Confidence | Code | Meaning |
| --- | --- | --- |
| `evidence` | `check_finding` | `GlossaryChecker` flags this unit for a corrected term, under the current policy |
| `evidence` | `previous_wording` | A translation whose source activates the term still holds the previous required wording |
| `evidence` | `proposal_provenance` | An accepted proposal recorded another version of the term and its value is still in the row |
| `textual` | `previous_wording` | The text holds previous wording with no source that requires the new one: manual text, source rows, removed terms |
| `textual` | `linked_term_absent` | The source activates a concept and the row lacks the concept's preferred term in its language |
| `textual` | `source_alias` | A row in the term's language uses a newly added source alias |
| `uncertain` | `conditional_term` | A finding on a term with applicability or contextual status. Needs judging |
| `uncertain` | `unverifiable_term` | A changed term the source mentions, with no wording set for this language |
| `uncertain` | `term_guidance` | Notes, knowledge, applicability or status changed and the text mentions the term |
| `uncertain` | `rule_overlap`, `document_overlap` | At least 2 of up to 12 words from the changed lines occur in the unit |

Evidence is not a claim that the text is wrong. The UI and copy say candidates, and the skill tells the agent to read first. A rule or document with fewer than 2 such words is `unscoped`: named on the scan and in the UI as not searched.

Text that passes the checks for a wording correction is excluded. Each reason carries the source (`type`, `id`, `label`, `change`, `revision_id`, `version`, for terms `concept_id`), the unit, field path, label and surface. The scan carries `policy_snapshot_id` and `base_snapshot_id`. Up to 12 reasons per row, the rest counted.

All prose units of the block schema count, including nested blocks, tables and `meta`. Reads `current_version`, like operations do.

## From candidates to a draft

`POST .../scans/{scan}/maintenance` calls `AiOperations::create` with kind `general`, skill `knowledge-maintenance`, `scope: {type: content, ids: [...], recursive: false}`, then `savePlan` with a deterministic plan. Result: state `draft`, no approved plan, no budget, no planning run, no policy pin, no provider call. The editor approves in the existing thread and panel with `plan_hash`, `scope_hash` and `max_usd`.

Why `general` and not `translation`: `TranslationWork` holds a translation whose source is unchanged as `current`, and that is correct for ordinary translation work. Over explicit rows, `propose_update` has no lineage gate, so no change to the shared translation runtime is needed. `an_approved_maintenance_batch_can_propose_on_a_translation_whose_source_never_changed` proves this through approval with the fake driver. It creates a pending proposal on the translation row and leaves the content unchanged.

Selection is `candidate_ids` (max 1000, same as `scope.ids`) or a filter (`confidence`, `surface`, `language`) taking the first 1000 open live rows and returning `remaining`. Taken rows store `operation_id`. A cancelled operation releases them on the next draft. A lock stops two clicks from taking one candidate twice.

Stale guards: the response reports `deleted`, `changed` and `unavailable`, deleted rows are left out, and the list shows `current_state` per row. After that the normal checks apply: `freeze` records the base version, `ensureScopeUnchanged` refuses approval when a row left, `recheck` flags `version_changed` and skips deleted rows, and proposals carry `version_id`.

The prompt names the corrections the selected candidates cite, up to 40, with required, forbidden and previous wording. `lookup_glossary` decides over it, given the parent's hook, since the policy pins at approval and may be newer than the scan.

## API

All under `/mgmt/v1/spaces/{space}`, behind `space.member`. Read needs `content.view`, scan and resume need `content.manage`, maintenance needs what any operation needs (`AiRun` create on `bulk:content`). Scans are found by `space_id`.

| Route | Result |
| --- | --- |
| `GET ai/knowledge-impact` | `{fingerprint, base: {snapshot_id, source, captured_at}|null, changed, changes, latest_scan}` |
| `POST ai/knowledge-impact/scans` `{mode?: changes|full}` | 202, scan. Returns the running one if any |
| `GET ai/knowledge-impact/scans/{scan}` | `{id, mode, state, stale, error, policy_snapshot_id, base_snapshot_id, progress: {scanned, total}, limited, candidates, by_confidence, changes, finished_at, created_at}` |
| `POST .../{scan}/resume` | Scan |
| `GET .../{scan}/candidates?confidence[]=&surface=&language=&open=&per_page=` | Paginated, max 200 per page |
| `POST .../{scan}/maintenance` | 201, `{operation, selected, deleted, changed, unavailable, remaining}` |

`changes`: `{counts, listed (max 50), omitted, unscoped (max 50)}`.

## Deploy

1. Management migration `2026_11_02_120000` runs in the normal deploy. Additive. It must land with the code: the model now filters on `deleted_at`.
2. Space migration `000026` needs `spaces:repair-databases`. Only discovery reads those tables, so everything else keeps working until then.
3. No backfill. Documents deleted before this migration stay gone and are reported as unavailable.

## Checks

SQLite in memory, PHP 8.5.12, fake driver, no paid calls, no live migration. `vendor/` and `node_modules/` cloned into the worktree, throwaway `APP_KEY`.

- `tests/Feature/Ai tests/Unit/Services/Ai`: 348 tests, 2,168 assertions pass. After the last backend edit (limited scans are no baseline), the retention, impact, policy, operation and settings-list files: 55 tests, 470 assertions pass.
- New: `AiKnowledgeImpactTest` 11 tests, `AiKnowledgeRetentionTest` 3 tests.
- Vitest for `knowledge-impact` and `glossary-import`: 28 tests pass.
- `bun run typecheck` and `bun run lint` pass. Pint on the owned PHP passes.
- Visual: a scratch Vite on port 3419 with no TLS (PID recorded, stopped) mounted the real panel with the real stylesheet and i18n. Scratch Playwright answered the API with synthetic fixtures. Not the app shell. Files deleted, the server on port 3000 (PID 79171) not touched.
  - Looked at: candidates 1440 dark, 390 dark, 1024 German light, read-only 1024 light, stopped 1024 dark, limited 1024 dark, no baseline 390 light. Running was captured but not opened. No page overflow at any width.
  - First pass had real phone defects, now fixed: two header buttons squeezed the title out, the reasons column was clipped, and the footer button was cut off. Buttons moved into the body. Under `@3xl` language, state and reasons stack in the entry cell. The shared pagination footer scrolls inside its own wrapper at 390.

## Not verified and known limits

- MySQL: `lockForUpdate`, `whereJsonContains`, `insertOrIgnore`, and the scan's speed on real volumes. Slice size comes from reading the code, not measurement.
- Queue workers: slices re-dispatch themselves; only the sync queue and manual `advance` ran. Lost jobs need the manual resume, since `Console/Kernel` is not in this slice.
- Real browser against real backend, real provider, editorial quality of the skill's proposals. None run.
- The batch context carries entries and the correction summary, not each candidate's reasons. The agent re-reads with `get_entry` and `lookup_glossary`.
- Document rule provenance is not per proposal, so a changed rule never yields `proposal_provenance`. Rule reasons are word overlap only.
- Entry `name` and slug, assets and data entries are not scanned.
- Open proposals on a row are not shown on candidates. The approved batch meets them as usual.
- Each completed scan moves the baseline. Candidates of an older scan are reachable only by a new audit, since the UI shows the latest scan.
- The panel lives on the space's Knowledge page. A team publication has no team-wide view: each space checks on its own.
- `es`, `fr`, `ru`, `tr` fall back to English in this module (`knowledge-impact/i18n`). No registration needed.
- Generated OpenAPI spec and local MCP parity are owed to W4 integration.
- `knowledge-maintenance` shows up in the bulk skill list on `bulk:content`. It stays useful there with a prompt naming the correction, but it is a visible new option.
