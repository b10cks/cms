# W7 T2: concept API

The contract T3 (sheet) and T4 (editor) build on. Rules are in [w7-glossary-amendments.md](w7-glossary-amendments.md). Neither task should touch term rows directly.

## Storage

- Management migration `2026_11_04_100000_add_category_to_ai_glossary_concepts`: nullable `category` (120), indexed with `glossary_id`.
- Status, audience, `case_sensitive` and `do_not_translate` stay per term. There is no data migration.

## Row ids

A row's `id` is the concept id, or `term:<id>` for a term with no concept. Reading never creates a concept. The first write that changes something does, and the response carries the new id. `term:<id>` keeps answering afterwards as that concept, so a stale screen still lands on the right row.

## Writer: `App\Services\Ai\Knowledge\Concepts\GlossaryConcepts`

The only code that writes a concept. One instance holds a glossary's terms in memory. Writes change that state and cost no query until `commit()`.

```php
// One unit under the glossary's lock, committed for you. Endpoints use this.
$id = GlossaryConcepts::transaction($glossary, fn (GlossaryConcepts $c) => $c->update($rowId, $input));

// Batch, for T3. open() holds no lock or transaction, so yield between rows.
$concepts = GlossaryConcepts::open($glossary);
$concepts->rowIdForKey('cloud-bed');          // ?string; null when no term is left on that key
$concepts->rowIdForWording('de', 'Wolkenbett'); // ?string; ignores case
$concepts->hasRow($rowId);                    // bool
$id = $concepts->create($input);              // throws GlossaryConceptConflict
$id = $concepts->update($rowId, $input);      // throws GlossaryConceptConflict or ModelNotFoundException
$concepts->remove($rowId);
$result = $concepts->commit($revisionId);     // one transaction, chunks of 500
$concepts->rowFacts($rowId);                  // key, wording and translations by normalized locale (added by T3)
$concepts->pendingWrites();                   // 0 means commit has nothing to do (added by T3)
$concepts->commit($revisionId, $onChange);    // hands each change to $onChange instead of holding them (added by T3)
```

A refused write throws before anything changes, so T3 catches `GlossaryConceptConflict`, records the row error and carries on. Use `$conflict->reason`, `getMessage()` and `$conflict->details`.

`commit(?string $revisionId)` returns `{added, updated, removed, changes}`, counted in terms. With a revision id it stamps `revision_id` on terms whose own fields changed and fills `changes` with `{action, concept_id, term_id, before, after}` per term, using `ImportedTerm::snapshot`. Without one, `changes` is empty. **The writer does not create the revision or move `head_revision_id`.** T3 does that in its own step. `commit()` refuses with `stale_glossary` if the glossary changed since `open()`, compared by term and concept ids and `updated_at`, so two writes to one row in the same second go unseen.

### Input

```php
[
    'key' => ?string,               // ^[A-Za-z0-9._:-]{1,120}$
    'category' => ?string,
    'status' => 'preferred'|'prohibited'|'contextual',
    'applicability' => ?list<string>,
    'case_sensitive' => bool,
    'do_not_translate' => bool,
    'languages' => [
        'de' => [
            'term' => string,       // required for a language the row does not have
            'source_aliases' => ?list<string>,
            'forbidden' => ?list<string>,
            'definition' => ?string,
            'notes' => ?string,
            'status' => ?string,    // override; null clears it
            'applicability' => ?list<string>, // override; null clears it
        ],
    ],
    'remove_languages' => list<string>,
]
```

`SaveAiGlossaryConceptRowRequest::conceptRules(creating: bool)` holds the limits, for T3 to validate each row: 20 forbidden, 100 aliases, 50 applicability, 255 per wording, 120 per key and category, 10000 per definition and notes. The writer trusts its input apart from trimming lists and dropping blanks.

### Semantics

- **Omitted means keep.** An omitted field or language keeps its stored value. T3 maps a blank cell to an omitted key. An explicit null clears `category`, `key`, `definition`, `notes` and lists. A language goes only through `remove_languages`, and the last one refuses with `last_language`.
- **Shared on read.** `status` and `applicability` are the most common value across the terms. A tie goes to the term in the space's default language, then the alphabetically first locale. A language that differs reports under `overrides`. Audience compares as a set. `case_sensitive` and `do_not_translate` are read the same way but have no overrides.
- **Shared on write.** A sent `status` or `applicability` reaches the terms holding the old shared value and leaves overrides alone. `case_sensitive` and `do_not_translate`, when sent, reach every term. A partial write never flattens a mixed value it was not asked to change.
- **Overrides can become the majority.** Setting two of three languages to one status makes that the shared value on the next read and reports the third as the override. The effective per-language values are what you set.
- **Defaults.** A new concept with no `status` is `preferred`. A new language takes the row's shared values unless it sends its own. A status of null on a stored term stays null until something writes it: it behaves differently in the matcher, so it is reported honestly rather than as `preferred`.
- **Prohibited.** Via `ImportedTerm::attributes`, a prohibited term's own wording goes in `forbidden`. `proposalOf` takes it back out, so reads show the explicit list and a status change or rename stays clean.
- **Wording sync.** Other languages' `translations` follow a term's wording while it supplies wording (preferred or null status). A rename, a status change or a removal updates only what moved, and removal leaves a translation someone replaced by hand. This shares `ImportedTerm::withWording` and `withoutWording` with `PublishGlossaryImport`.
- **Knowledge.** `rules`, `qualifiers` and `unknown_fields` survive a write. `note` is rebuilt from definition and notes as Markdown import does, truncated at 2000. A note from before `knowledge` reads as `notes`.
- **No change, no write.** A write equal to the stored values changes nothing, and a conceptless term stays conceptless.
- **Fingerprints.** Source entries are left alone, so a later Markdown import compares the changed term with its baseline and reports `cms_edited_since_import`. Tested.
- **Removal.** Deleting a row removes its terms. The concept goes too, unless a source entry refers to it: `source_entries.concept_id` cascades, and that row is how a later import reports `term_deleted_in_cms`. A retained empty concept has no row, and creating a row with its key revives it.
- **Locales.** Input may be any spelling. A language matches on `GlossaryMatcher::normalizeLocale`, and a new term stores the code as given. A locale the space has not enabled is accepted and shows as unused.

### Conflicts: `App\Exceptions\GlossaryConceptConflict`

| `reason` | HTTP | `details` |
| --- | --- | --- |
| `wording_conflict` | 409 | `locale`, `wording`, `concept: {id, key, term}` |
| `key_conflict` | 409 | `key`, `concept: {id, key, term}` |
| `stale_glossary` | 409 | |
| `term_required` | 422 | `locale` when a language lacks a term |
| `last_language` | 422 | |

Wording compares case-insensitively within one exact normalized locale, and only when the wording changes or the term is new. Rows that already share a wording stay editable. Aliases and forbidden wording never conflict.

## Endpoints

Under `/mgmt/v1/{spaces/{space}|teams/{team}}/ai/glossaries/{glossary}`. Reads need `viewAny`, writes `manage`. A glossary inherited from a team reads normally with `meta.read_only: true` and returns 403 on writes. Other owners return 404.

### `GET concept-rows`

Query: `search`, `status` (`preferred`, `prohibited`, `contextual`, `none`), `category`, `uncategorized=1`, `audience`, `missing`, `page`, `per_page` (default 50, at most 200).

- `search`: part of a wording, alias or definition in any language, ignoring case.
- `status`, `audience`: a language of the row holds it, override or not. So do the facet counts, and they can add up to more than the row total.
- `missing`: no term for that locale. A base language counts for its regions.
- Sort: by the default language's term in that language's alphabet (`intl` collation), then rows with no term in it. A team has no default, so it uses the alphabetically first locale in use.

```jsonc
{
  "data": [{
    "id": "01J…", "concept_id": "01J…", "key": "cloud-bed", "category": "Beds",
    "status": "preferred", "applicability": ["B2C"], "case_sensitive": false, "do_not_translate": false,
    "languages": { "de": {
      "term_id": "01J…", "source_locale": "de", "term": "Wolkenbett",
      "source_aliases": ["WB"], "forbidden": [], "definition": null, "notes": null,
      "translations": { "en": "Cloud bed" }, "revision_id": null
    } },
    "overrides": { "fr": { "status": "contextual" } },
    "coverage": { "de": "own", "de-at": "fallback", "fr": "missing" }
  }],
  "links": { },
  "meta": {
    "current_page": 1, "last_page": 1, "per_page": 50, "total": 1,
    "read_only": false, "default_locale": "de",
    "available_locales": [
      { "code": "de", "default": true, "enabled": true, "unused": false },
      { "code": "fr", "default": false, "enabled": false, "unused": true }
    ],
    "facets": {
      "total": 1,
      "categories": [{ "value": "Beds", "count": 1 }], "uncategorized": 0,
      "audiences": [{ "value": "B2C", "count": 1 }],
      "statuses": { "preferred": 1, "prohibited": 0, "contextual": 0, "none": 0 },
      "locales": [{ "value": "de", "count": 1 }],
      "missing": [{ "value": "fr", "count": 0 }]
    }
  }
}
```

`facets.total` and `facets.missing` (added by T4) hold the row count and, for every locale that is not unused, how many rows the `missing` filter would return. `languages` and `coverage` are keyed by the codes in `available_locales`. `SpaceI18nSettingsService` lowercases a space's language codes, so a stored `de-AT` appears as `de-at`, with its spelling kept in `source_locale`. Facets cover the whole glossary and ignore the filters. `available_locales`: in a space, the enabled languages with the default first, then stored others. `unused` is true unless an enabled language can use that term, so a stored `de` with `de-at` enabled is `enabled: false, unused: false`. In a team, the locales in use. `translations` is read-only on rows: the writer owns it.

### `GET concept-rows/{row}`

One row, for a link to a concept that is not on the current page. Added by T4.

### `POST concept-rows`, `PATCH concept-rows/{row}`

Body as the writer input. `languages` with at least one entry is required on POST. Returns the row, 201 on create.

### `DELETE concept-rows/{row}`

204. Works for a conceptless term.

### `POST concept-rows/bulk`

```json
{ "row_ids": ["…"], "action": "set_category", "category": "Beds" }
```

`action`: `set_category` (`category`, nullable), `set_status` (`status`), `set_audience` (`applicability`), `delete`. At most 5000 ids, one transaction. An unknown id refuses the whole request with 422 under `row_ids`. Returns `{ "data": { "updated": 2, "deleted": 0 } }`.

### `POST glossary-check`

Body: `{text, source_locale, target_locale?, output?}`, each text at most 50000 characters. This one glossary's terms only, whether or not it is enabled. No provider call and no write.

```jsonc
{ "data": {
  "terms": [{
    "term": "Wolkenbett", "required": "Cloud bed",
    // ...every other field LookupGlossaryTool returns for the term
    "term_id": "01J…", "row_id": "01J…",
    "matches": [{ "start": 7, "end": 17, "text": "Wolkenbett", "via": "term" }]
  }],
  "findings": null
} }
```

Offsets are UTF-16 code units, so `text.slice(start, end)` is the match, also after an emoji. `matches` can overlap. `findings` is what `GlossaryChecker::check` returns, null unless `output` is sent. Without `target_locale` the check treats the output as the source language. The rows and findings come from calling the tool and the checker, so T1's rule changes reach this endpoint without changes here.

## Frontend

- `resources/js/api/resources/ai-knowledge.ts`: `conceptRows`, `createConceptRow`, `updateConceptRow`, `deleteConceptRow`, `bulkConceptRows`, `checkGlossaryText`, and the types (`AiGlossaryConceptRow`, `AiGlossaryConceptPayload`, `AiGlossaryConceptConflict`, `AiGlossaryCheckResult` and others).
- `useAiGlossaryConceptRows(owner, glossaryId)`: `useRowsQuery(filters)` with `keepPreviousData`, `useCreateRowMutation`, `useUpdateRowMutation`, `useDeleteRowMutation`, `useBulkMutation`, `useCheckMutation`. Mutations leave errors to the caller, because a conflict names a concept. Writes invalidate the rows and the glossary list.
- `queryKeys.aiKnowledge(owner).glossaryConceptRows(glossaryId)`.
- `auto-imports.d.ts` was edited by hand with the two lines the generator writes. Vite has not run in this worktree.

## MCP and docs

- `ai.glossaries.*` and `ai.teamGlossaries.*`: `conceptRows`, `createConceptRow`, `updateConceptRow`, `deleteConceptRow`, `bulkConceptRows`, `checkText`. New argument `rowId`, which never falls back to `id`. `OperationsMirrorTest` lists them as not in the SDK yet.
- `docs/api/management-api.md` has a section, and `docs/guides/mcp-server.md` a sentence.
- `docs/public/specs/mgmt_v1.json` is regenerated with `php artisan docs:generate --prefix=mgmt/v1`, as `docs/api/openapi.md` asks. Compared as JSON, it adds 8 paths and the new schemas, and one change is unrelated drift the file already had (`contents/import`). The text diff looks far larger because paths moved. If T1 also regenerates, resolve a conflict by regenerating after the merge.

## Benchmark

SQLite in memory through the test suite, PHP 8.5, Apple Silicon. 5000 concepts with 6 languages (30000 terms), each with aliases, forbidden, definition and notes. Peak includes about 67 MB of test framework.

| Step | Time | Peak |
| --- | --- | --- |
| Create 5000 x 6, in memory | 2.51 s | 161 MB |
| Create, commit, no revision | 0.44 s | 193 MB |
| Open the 30000-term glossary | 0.75 s | 255 MB |
| Rewrite every term, in memory | 3.55 s | 309 MB |
| Rewrite commit with a revision id and `changes` | 1.95 s | 493 MB |
| One `transaction()` update on that glossary | 0.81 s | |
| `GET concept-rows` service call, one page | 0.15 s | |
| The same with `search` | 0.16 s | |
| `glossary-check` service call | 2.50 s | |

The batch form runs no query per row: a test asserts 0 queries during 120 creates and fewer than 15 in total.

Three costs to plan around:

- **Memory.** A full rewrite with `changes` reached 493 MB, close to the 512 MB limit in this test run. `changes` holds a before and after per term and takes about 180 MB of that. T3 writes those into a revision, so a full-rewrite import of this size needs its memory checked, or a more compact record.
- **Single writes.** Each loads the whole glossary, so 0.8 s at this size and milliseconds at the 201 concepts of the reference glossary.
- **`glossary-check`.** It runs one regular expression per term, 2.5 s at this size. That matches what a run pays per text, not a new cost.

## Checks

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit` on `AiGlossaryConceptRowsTest` (15 tests), `AiGlossaryMarkdownImportTest`, `tests/Feature/Mcp`, `AiSettingsListTest`, `SpaceAiSettingsTest`, `AiKnowledgeImpactTest`, `AiKnowledgeRetentionTest`, `tests/Feature/Documentation` | 86 tests pass, 1 skipped |
| Migrate and roll back on a temporary SQLite file | Passes, `category` gone after rollback |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| `vendor/bin/pint --test` on changed PHP | Passes |

`OperationsMirrorTest` skips here: the SDK checkout is not next to the worktree.

## Not exercised

- **MySQL.** Everything ran on SQLite. `upsert` becomes `ON DUPLICATE KEY UPDATE`, safe here because `id` and `(concept_id, source_locale)` are the only unique keys and writes never change a term's locale. The benchmark needs repeating on MySQL, as the Markdown publish timing does.
- **Concurrent writers.** The stale check is tested with a second write between `open()` and `commit()`, not with parallel requests or row locks.
- **The frontend contract in a browser.** It is typechecked only, and T4 is its first caller.

## Known limits

- A term whose `note` was edited through the old term endpoint after an import keeps a stale `knowledge`. The row shows `knowledge`, and the next write to its definition or notes replaces that `note`. T4 removes that UI.
- Two terms of one concept in spellings of one locale (`de`, `DE`) would collapse to one language in a row. The Markdown publish checks this slot on normalized locales, so this needs hand-edited data.
