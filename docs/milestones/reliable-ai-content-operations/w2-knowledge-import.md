# W2 knowledge import evidence

Base commit: `6c351e9f`. Backend foundation slice only. The full brief is not complete.

Owned paths changed: glossary models, `app/Services/Ai/Knowledge/Import/*`, `GlossaryTerm`, `GlossaryMatcher`, `KnowledgeResolver`, `app/Actions/Ai/Glossary/*`, glossary requests, resources and controller, additive `routes/private_mgmt.php`, migration `2026_11_02_100000`, `tests/Feature/Ai/AiGlossaryMarkdownImportTest.php`, `tests/Unit/Services/Ai/MarkdownGlossaryParserTest.php`.

## Behavior

An upload creates a draft and touches no live term. The reviewer resolves entries and publishes a selection in one transaction, which writes one immutable revision. CSV, XLSX, TBX and manual term APIs work as before.

### Identity

- Draft: glossary, `source_key`, locale, content hash, mapping hash, base revision. One draft is open per glossary, source and locale, enforced by a unique index.
- The same content and mapping against the same base returns the same draft with HTTP 200. Line endings and a BOM do not count as content.
- The same content and mapping with a moved base re-diffs the open draft in place. New content or a new mapping supersedes the open draft and creates a new one (201).
- A decision carries to the new diff only if the entry's parsed proposal and the live term it was made against are both unchanged.
- Entry key within a source: explicit concept key (`## Term {#key}` or a field mapped to `concept_key`), else the folded heading.
- Term lookup order: explicit concept key or the source's earlier publication, then the exact term in the same locale. Wording in another language never links anything.
- A rename without an explicit key shows as added plus removed. Linking the new entry to the concept or term updates that term and dismisses the removal.

### Diff kinds

| Kind | Meaning | Publishable |
| --- | --- | --- |
| `added` | No live term; no other locale needs linking, or an explicit key applies | Yes |
| `changed` | Source moved, live term equals its last publication | Yes |
| `removed` | The source's published entry left the source | Only with `confirm_removals` |
| `conflicting` | Has blockers other than `concept_unlinked` | After resolution |
| `unlinked` | New entry, other locales exist, no explicit link | After `link` or `new_concept` |
| `unchanged` | Nothing to do. `reasons` holds `cms_edited_since_import` if only the CMS moved | No |

Reasons and blockers: `status_unmapped`, `concept_unlinked`, `cms_edited_since_import`, `term_deleted_in_cms`, `existing_term_not_from_source`, `translation_edited_in_cms`, `duplicate_entry`.

Entry states: `pending`, `resolved`, `dismissed`, `published`, `settled`. An entry can publish when its state is `pending` or `resolved` and `blockers` is empty.

### Fields

| Field | Live column | Effect |
| --- | --- | --- |
| Preferred wording | `term` with `status: preferred` | Feeds linked locales' `translations`, so `GlossaryTerm::translationFor` stays the only wording resolver |
| Prohibited wording | `term` with `status: prohibited` | The heading goes into `forbidden`; never required output |
| Contextual wording | `term` with `status: contextual` | No deterministic rule; conditions stay in free text |
| Source aliases | `source_aliases` | Never added to `forbidden` or `translations` |
| Forbidden output | `forbidden` | Only an explicitly mapped field, plus the heading of a prohibited term |
| Exact case, protected | `case_sensitive`, `do_not_translate` | From a mapped qualifier: `exact_case`, `do_not_translate`, `protected` (both) |
| Audience, applicability | `applicability` | Free labels |
| Definition, notes, rules, qualifiers, unknown fields | `knowledge` JSON | Attributable through `revision_id` and the revision's `section_text` |
| Legacy prompt note | `note` | Definition and notes, cut to the existing 2000-character limit; the full text stays in `knowledge` |

Unmapped labels stay in `unknown_fields` and produce an aggregated warning. An unmapped status blocks the entry until the reviewer sets it or re-uploads with a mapping.

Default mapping holds common English and German labels and status words (`gesetzt`, `verboten`, `Kontextvariante`). A source-specific label like `Rahmen` needs an explicit mapping.

## API

Prefix: `/mgmt/v1/spaces/{space}/ai/glossaries/{glossary}` or `/mgmt/v1/teams/{team}/ai/glossaries/{glossary}`. Reads need `ai.view` or `team.view`, writes `ai.manage` or `team.ai.manage`. Rows load through the owner, so another owner's IDs return 404.

| Method and path | Purpose |
| --- | --- |
| `POST markdown-imports` | Create or return a draft |
| `GET markdown-imports` | Paginated drafts, newest first |
| `GET markdown-imports/{id}` | One draft |
| `GET markdown-imports/{id}/entries?kind=&state=&per_page=` | Paginated entries (default 50, max 200) |
| `POST markdown-imports/{id}/resolve` | Record decisions |
| `POST markdown-imports/{id}/refresh` | Re-diff against the live glossary |
| `POST markdown-imports/{id}/publish` | Publish a selection |
| `GET concepts?search=` | Link targets with their per-locale terms |
| `GET revisions`, `GET revisions/{id}` | Provenance; `entries` only on show |

### Create

JSON or multipart. `file` (md, markdown, txt; 10 MB) or `markdown`, not both. `locale` is required. `source_key` defaults to `default` and stays the same across file renames. `mapping` is an object, or a JSON string for multipart:

```json
{
  "locale": "de",
  "source_key": "handbook",
  "markdown": "## Term\n\n**Status** gesetzt\n",
  "mapping": {
    "entry_level": 3,
    "fields": { "Rahmen": "applicability", "Quelle": "reference" },
    "statuses": { "in Prüfung": "contextual" },
    "qualifiers": { "Eigenname": "protected" }
  }
}
```

Field targets: `status`, `source_aliases`, `forbidden`, `applicability`, `definition`, `notes`, `rules`, `concept_key`, `reference`. Statuses: `preferred`, `prohibited`, `contextual`. A source with no entries returns 422, so an empty file can never read as removing everything.

### Draft response

```json
{
  "data": {
    "id": "…", "glossary_id": "…", "source_key": "handbook", "locale": "de",
    "filename": null, "content_hash": "…", "mapping_hash": "…", "mapping": {},
    "base_revision_id": null, "status": "open",
    "counts": { "kinds": { "added": 1 }, "states": { "pending": 1 } },
    "warnings": [{ "code": "unmapped_label", "label": "Rahmen", "line": 7, "count": 188 }],
    "document_sections": [{ "path": ["Title"], "text": "…", "line": 3 }],
    "created_by": "…", "created_at": "…", "updated_at": "…"
  }
}
```

Status is `open`, `closed` or `superseded`. Warning codes: `unmapped_label`, `unmapped_status`, `unmapped_qualifier`, `status_missing`, `invalid_concept_key`, `invalid_heading`, `duplicate_entry`.

### Entry

```json
{
  "id": "…", "position": 0, "entry_key": "term", "kind": "added", "state": "pending",
  "publishable": true, "requires_confirmation": false,
  "proposal": {
    "term": "Term", "concept_key": null, "status": "preferred", "status_label": "gesetzt",
    "source_aliases": [], "forbidden": [], "case_sensitive": false, "do_not_translate": false,
    "applicability": [], "definition": null, "notes": null, "rules": [], "qualifiers": [],
    "unknown_fields": [{ "label": "…", "value": "…" }], "section_path": []
  },
  "base": null, "term_id": null, "concept_id": null,
  "reasons": [], "blockers": [], "resolution": null,
  "section_text": "## Term\n…", "source_line": 1, "revision_id": null
}
```

`proposal` is null on removals. `base` is the live term when the entry was diffed.

### Resolve

```json
{ "resolutions": [
  { "entry_id": "…", "action": "link", "concept_id": "…", "status": "preferred" },
  { "entry_id": "…", "action": "link", "term_id": "…" },
  { "entry_id": "…", "action": "new_concept" },
  { "entry_id": "…", "action": "use_import" },
  { "entry_id": "…", "action": "keep_cms" }
] }
```

- `keep_cms` dismisses the entry. A decision can be changed while the draft is open.
- `use_import` clears conflict blockers but not `concept_unlinked`.
- `link` takes `concept_id` or `term_id`; `term_id` is how to link a term with no concept yet.
- Returns the draft. Only the draft changes, including when linking a term with no concept.

### Publish

```json
{ "base_revision_id": null, "entry_ids": ["…"], "confirm_removals": ["…"] }
```

`base_revision_id` is required and may be null. The response is `{ "data": { "revision": {…with entries}, "import": {…} } }`.

| Status and code | When |
| --- | --- |
| 409 `stale_base` | Request or draft base differs from the head. Includes `head_revision_id`, `base_revision_id` |
| 409 `stale_entries` | A live term changed, or its wording or concept slot is taken. Includes `entry_ids` |
| 409 `import_closed` | Draft is not open |
| 422 `entry_ids` | Unknown or unpublishable entry, or two entries with one wording |
| 422 `confirm_removals` | A selected removal is not confirmed |

Any refusal leaves the glossary untouched. After a publication the draft moves to the new base, and it closes once nothing is `pending` or `resolved`.

### Revision

```json
{
  "id": "…", "glossary_id": "…", "number": 1, "import_id": "…", "source_key": "…", "locale": "de",
  "filename": null, "content_hash": "…", "mapping_hash": "…", "base_revision_id": null,
  "published_by": "…", "summary": { "added": 1, "updated": 0, "removed": 0 }, "created_at": "…",
  "entries": [{
    "entry_id": "…", "entry_key": "…", "action": "added", "concept_id": "…", "term_id": "…",
    "before": null, "after": {},
    "linked_updates": [{ "term_id": "…", "before": {}, "after": {} }],
    "reasons": [], "resolution": null, "proposal": {}, "section_text": "…", "source_line": 1
  }]
}
```

The model refuses updates and deletes. Rows go only when the glossary is deleted, through the database cascade.

Additive fields: terms gain `concept_id`, `status`, `source_aliases`, `applicability`, `knowledge`, `revision_id`. The term save request accepts `status`, `source_aliases`, `applicability`. Glossaries gain `head_revision_id`.

## Schema

Migration `2026_11_02_100000_create_ai_glossary_import_tables`, management database, additive.

- `ai_glossary_concepts`: `id`, `glossary_id`, `key` (nullable), timestamps. Unique `glossary_id, key`.
- `ai_glossary_imports`: `id`, `glossary_id`, `source_key`, `locale`, `filename`, `content_hash`, `mapping_hash`, `mapping`, `base_revision_id`, `status`, `open_key` (unique), `markdown`, `document_sections`, `warnings`, `created_by`, timestamps.
- `ai_glossary_import_entries`: `id`, `import_id`, `position`, `entry_key`, `kind`, `state`, `proposal`, `proposal_hash`, `term_id`, `concept_id`, `base`, `base_fingerprint`, `reasons`, `blockers`, `resolution`, `section_text`, `source_line`, `revision_id`, timestamps. Unique `import_id, entry_key`.
- `ai_glossary_revisions`: `id`, `glossary_id`, `number`, `import_id`, `source_key`, `locale`, `filename`, `content_hash`, `mapping_hash`, `base_revision_id`, `published_by`, `summary`, `entries`, `created_at`. Unique `glossary_id, number`.
- `ai_glossary_source_entries`: `id`, `glossary_id`, `source_key`, `locale`, `entry_key`, `concept_id`, `term_id`, `fingerprint`, `revision_id`, timestamps. Unique on the source, locale and key. Survives a term deleted in the CMS, which is how that deletion is detected.
- `ai_glossaries`: adds `head_revision_id`.
- `ai_glossary_terms`: adds `concept_id`, `status`, `source_aliases`, `applicability`, `knowledge`, `revision_id`. Unique `concept_id, source_locale`.

On MySQL `entry_key` and concept `key` use `utf8mb4_bin`, because the default collation would treat `färbe` and `farbe` as one key.

## Migration and backfill

Run the management migration in the normal deploy. No data is rewritten and no backfill command is needed.

- Existing terms keep all new columns null and resolve as before. A null `status` behaves as preferred wording.
- `head_revision_id` stays null until a glossary's first publication, so its first publish sends `base_revision_id: null`.
- Concepts are created lazily by `AiGlossaryTerm::ensureConcept()`, the first time a publication touches or links a term.
- A heading equal to an existing term in the same locale is a conflict (`existing_term_not_from_source`). The reviewer adopts it with `use_import` or keeps it. The term is never recreated.
- Rollback drops the new tables and columns. Published imports' wording stays in `term`, `translations`, `forbidden` and `note`.

## Hooks for later tasks

- Policy snapshot: `KnowledgeResolver::glossaryRevisions(Space)` returns `glossary_id` and `head_revision_id` per enabled glossary. Manual term edits create no revision, so freeze the terms next to it. `GlossaryTerm` carries `conceptId`, `revisionId`, `status`, `applicability`, `knowledge` through the frozen shape.
- `ContextBuilder`: to activate aliases, call `match($source, $locale, withAliases: true)` and freeze the result. Default stays off, so no runtime behavior changes yet.
- Semantic checks: read `applicability` and `knowledge` from the frozen term, and `section_text` from the revision named by `revisionId`.
- Maintenance discovery: `GET revisions/{id}` gives before, after and concept per entry. Nothing starts paid work.
- Frontend: all endpoints above; `GET concepts` provides link targets.

## Integration requests

1. `GlossaryChecker` is outside this slice and needs two changes before imports are used in runs:
   - It checks every term's `forbidden` against every target locale. A prohibited German word that is valid English would flag English output. Scope `forbidden` to `sameLanguage($term->sourceLocale, $targetLocale)` for terms with a non-null `status`, and keep today's behavior for legacy terms.
   - For a prohibited term, `expected` is the prohibited word itself. Omit `expected` there, or point it at preferred wording.
2. `ContextBuilder` freezes all terms with `forbidden`, including prohibited ones, with their `knowledge`. This grows run setup by the size of those terms' text. Consider dropping `knowledge` from the prompt-only copy if size matters.
3. `routes/private_mgmt.php` fails Pint's `ordered_imports` at the base commit as well as here. I left the existing order alone.
4. API specs, MCP operations, frontend and locales are not touched, as assigned. Controller docblocks are not written for the OpenAPI generator yet.

## Checks

SQLite in memory, PHP 8.5.12, PHPUnit 13.4.5, no paid calls. Real `vendor/` copied into the worktree.

- `vendor/bin/phpunit tests/Unit/Services/Ai tests/Feature/Ai/AiGlossaryMarkdownImportTest.php tests/Feature/Ai/AiSettingsListTest.php tests/Feature/Ai/SpaceAiSettingsTest.php tests/Feature/Ai/AiSkillResolutionTest.php tests/Feature/Ai/AiLearningTest.php tests/Feature/Ai/AiChangesetTest.php tests/Feature/Ai/AiTranslationOperationsTest.php tests/Feature/Ai/AiTranslationMemoryTest.php tests/Feature/Ai/AiRunServiceTest.php tests/Feature/Ai/AiSpaceDatabaseTest.php tests/Feature/Mcp/AiMcpToolsTest.php`: 155 tests, 775 assertions, all pass.
- New tests alone: 15 feature tests, 6 parser and DTO unit tests.
- Temporarily disabling each of these made at least one test fail: the live-state check, removal confirmation, base check, `concept_unlinked`, `cms_edited_since_import`.
- Migrate, roll back one step and migrate again on a temporary SQLite file: pass.
- `vendor/bin/pint --test` on all changed PHP: passes except the `ordered_imports` finding above.
- A scratch run of the parser over the reference attachment, not committed: 188 entries; 175 preferred, 12 prohibited, 1 contextual; 451 aliases. With `Rahmen` and the proper-name qualifier mapped: 0 warnings, 15 protected terms.

## Remaining gaps and limits

- Not verified: MySQL, including the collation, the implicit foreign key index on rollback and row locks. `lockForUpdate` is a no-op on SQLite, so true concurrent publish was not exercised.
- Manual term writes take no glossary lock. A write that lands after the publish's live-state check waits on the row lock and then wins as last writer. The next import reports it as a CMS edit.
- `document_sections` keeps document-wide text on the draft only. It is not published as knowledge or written into revisions yet. The content hash and stored draft Markdown keep it attributable.
- A removed and later re-added heading has no binding, so it returns as `unlinked` or `added`. The old concept stays available for linking.
- A kept removal (`keep_cms`) is proposed again when new content creates a new diff unless the decision carries over, which it does if the live term is unchanged.
- No endpoint discards a draft; a new upload replaces it.
- A `use_import` or `link` overwrites a linked term's wording for this locale at publish, with before and after recorded in `linked_updates`. `link` raises no separate conflict for this because linking is itself the explicit decision.
- Semantic evaluation, term-change impact indexing, snapshots, UI, API spec and MCP parity are later tasks. D10 is untouched.
