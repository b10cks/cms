# W7 T3: glossary sheet

Full-field CSV and XLSX import and export, a template, and the shared dialog with streaming. Rules are in [w7-glossary-amendments.md](w7-glossary-amendments.md), the writer in [w7-concept-api.md](w7-concept-api.md). The person-facing guide is [docs/guides/glossary-sheet.md](../../guides/glossary-sheet.md).

## Format as implemented

`concept, category, status, audience, case_sensitive, protected`, then per language `term:<l>, aliases:<l>, banned:<l>, definition:<l>, notes:<l>, status:<l>, audience:<l>`. Exports put the default language first.

Reading:

- Headers ignore case, order and locale spelling, and a BOM. CSV takes `,`, `;` or tab, chosen from the header line. Bytes that are not UTF-8 read as Windows-1252.
- XLSX reads the first sheet, whichever was left open. A formula counts as the value Excel saved and is never evaluated.
- A blank cell becomes an omitted key. Flags read `yes/no`, `true/false`, `1/0`, `x` and the German `ja/nein`, `wahr/falsch`. List values split on `;`, and `\;` is a literal semicolon.
- An unknown or repeated column goes to `ignored_fields` once. A cell past the last header is a row error, since it usually means an unquoted separator shifted the row.
- A header holding `source_locale`, and TBX, go through the old code unchanged.

Row identity, in order:

1. An existing `concept` key.
2. The default-language term, ignoring case (team: the first `term:` column).
3. When the default-language cell is blank, or the holder has no term in that language, the row's other terms.
4. No match creates a row.

### Decisions beyond the task file

- **Step 3.** Without it, a term from before concepts with no default-language term cannot round trip. It moves nothing: it only adds a language to a row that lacks one, or leaves that language alone.
- **A new key on a keyless row sets that key.** Otherwise adding keys to a downloaded sheet would turn every row into a wording conflict. A holder with a different key stays a conflict.
- **Stored translations export as `term:<l>`.** Every glossary built with the old term dialog holds its languages in `translations`, so an export without them would look empty. Reading back the same wording changes nothing. A changed wording creates a real term in that language.
- **A flag that differs between a concept's languages exports blank.** A value would flatten it, because there is no per-language flag column.
- **"First wins" works by resolved row.** Two file rows that reach one row are an error on the second. Two rows with different keys may share a wording that is already stored, which a stored homonym needs. New rows sharing a wording still conflict through the writer.

Results: `summary` adds `added`, `updated`, `unchanged`, counted in rows. `total_success` is those three together, so a file where everything failed reports 0. `errors` hold `{row, column, message}` with the number the spreadsheet shows. `warnings` holds one `unused_locales`, and `revision_id` is null when nothing changed.

### Revision

One `AiGlossaryRevision` with `source_key: sheet`, `import_id: null`, the filename and the file's hash, written with the commit in one transaction, which also moves `head_revision_id`. An open Markdown draft then gets `stale_base` until refreshed, tested.

The record is compact, so it fits at any glossary size:

- Before and after keep only changed fields, plus wording, locale and key. Derived `translations` and a repeated `note` are dropped.
- A term whose only change is a sibling's wording has no entry and no count.
- Entries stop at 2000 (`GlossaryRevisionRecord::DETAIL_LIMIT`). `summary` still counts everything and adds `omitted`.
- A concept with a new or changed key or category gets its own entry (`term_id` null). A brand new concept's entry is listed but not counted, since its terms are.

No reader depends on full entries. `ImpactDetector` and the policy code use `head_revision_id` and terms. Revision UI changes are limited to: "Spreadsheet", the filename and "All languages" for a sheet revision, a line for `omitted`, and a category fact.

### Injection

`SpreadsheetValue::escape` prefixes `=`, `+`, `-`, `@`, tab and CR with `'`, and now also a value that already looks escaped, so `unescape` returns any value exactly. That second case is a small change for every exporter that shares the class. XLSX cells are written as explicit strings, so `1.50` and `TRUE` survive.

## Changes outside the task's files

`GlossaryConcepts` (T2) gained three additions, existing callers unaffected:

| Addition | Why |
| --- | --- |
| `commit($revisionId, $onChange)` | `changes` held every before and after, about 180 MB at 5000 x 6 by T2's measurement. A callback lets the record keep only what it needs. It also reports concept key and category changes, which `changes` never held. |
| `pendingWrites()` | Unchanged rows and the no-op import cannot be told apart otherwise. A category-only change reaches no term. |
| `rowFacts($rowId)` | Step 3 and stored translations need a row's wording, and loading the terms a second time would double the memory. |

Also: `WritesCsvDownload` takes an optional `escape` (RFC 4180 here, matching what Excel writes), and `ImportDialog` has a `summary` slot, with its existing summary as the default. `useImportTermsMutation` is removed because its only caller is gone.

## Dialog contract

`resources/js/components/ai-settings/glossary-import/GlossarySheetImportDialog.vue`, for T4 to mount:

```vue
<GlossarySheetImportDialog
  v-model:open="open"
  :owner="owner"
  :glossary-id="glossaryId"
  @imported="(result: AiGlossaryImportResult) => {}"
/>
```

It invalidates `queryKeys.aiKnowledge(owner).glossaries()`, which covers the list, terms, concept rows, drafts and revisions. Backend row messages are English. Dialog copy is in `glossaryImport.sheet` in `en` and `de`.

## Benchmark

5000 concepts in 6 languages, 6.8 MB of CSV, each language with aliases, banned, definition and notes. SQLite in memory through the test suite, PHP 8.5, Apple Silicon, with a 512 MB limit set for the whole run, which it stayed under. Steps ran in order in one process, so "start" is what earlier steps left behind: about 66 MB of test framework plus 47 MB per write. That growth comes from a `QueryExecuted` listener holding query data, most likely debugbar from `.env`, which `DEBUGBAR_ENABLED=false` in the shell did not remove. It was not traced further, so a production request should start lower.

| Step | Time | Peak | Start |
| --- | --- | --- | --- |
| Read CSV only | 0.10 s | 84 MB | 66 MB |
| Read XLSX only (1.2 MB file) | 1.59 s | 276 MB | 160 MB |
| Import CSV, create all | 15.1 s | 237 MB | 65 MB |
| Import CSV, unchanged | 14.4 s | 287 MB | 112 MB |
| Import CSV, full rewrite with revision | 18.0 s | 346 MB | 112 MB |
| Export CSV | 3.4 s | 225 MB | 160 MB |
| Export XLSX | 6.0 s | 306 MB | 163 MB |
| Import the CSV export, unchanged | 13.9 s | 363 MB | 161 MB |
| Import the XLSX export, unchanged | 15.2 s | 356 MB | 156 MB |

T2's full rewrite with `changes` peaked at 493 MB. The same size here peaks at 346 MB, with the sheet read included. Each file is read and let go before the glossary opens, so the sheet is never held twice, and XLSX costs about 116 MB at read time only.

Most of the time looks like per-row validation with `conceptRules()`, about 2 ms a row after spelling out the wildcards. That figure comes from subtracting T2's writer timings, not from a profiler. The stream reports every 50 rows and survives that. A non-streaming caller such as MCP has the 30 second CDN limit, and this size is close to it on a slower machine. The 10 MB upload limit holds 5000 x 6 at this density.

## Checks

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit tests/Feature/Ai tests/Feature/Mcp tests/Feature/Documentation` | Passes, 1 skipped (`OperationsMirrorTest`: no SDK checkout beside the worktree) |
| `vendor/bin/phpunit tests/Feature/Mgmt/ImportStreamingTest.php` | Passes |
| `bunx vitest run tests/js/components/ai-settings` | 40 pass |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| `vendor/bin/pint --test` on changed PHP | Passes |
| `php artisan docs:generate --prefix=mgmt/v1` | Regenerated: `template` and `format` on export, the import result schema |

Looked at the dialog rendered: empty and mixed result in light at 1280, all rows failed in dark at 390, progress in light at 390. No overflow, measured at 390. Fixed from looking: template links read as plain text, so they are underlined now, and the summary's "Everything else applied" was wrong when nothing applied. The dialog was mounted alone against a stubbed `fetch`, with real CSS, i18n and API client, because this worktree has no TLS certificates or local app.

## Not exercised

- **The full flow in the real app.** Upload through the running app, the download links with a real session, and the revision panel showing a sheet revision were not rendered. Feature tests cover the routes. T5 covers the rendered flow.
- **Real Excel.** XLSX files came from PhpSpreadsheet, not Excel or Numbers. The status choice list was checked by reading the file back, not by opening it.
- **MySQL and production PHP.** Everything ran on SQLite. Revision size stays small thanks to the cap, so `max_allowed_packet` should not be a concern, but the timing needs repeating there.
- **Parallel writers.** A write between parsing and commit gives `stale_glossary`, surfaced as a file error. That path has no test of its own beyond T2's writer test.
- **Dark at desktop and the German copy** were not looked at separately.

## Known limits

- Two keyless rows sharing a default-language wording export fine but read back with a row error on the second, since nothing tells them apart. Giving them keys fixes it.
- A stored value the writer would normalize reports as updated once on the first round trip, then settles: a note with trailing whitespace, a repeated alias, or one audience in a different order across languages.
- `status:<l>` and `audience:<l>` cannot clear an override, because blank keeps. Writing the shared value in the cell has the same effect.
