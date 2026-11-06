# W9 model

Evidence for task S1 of [W9](w9-simple-glossary.md), brought up to the current contract on 2026-11-04. A glossary row is one meaning: per language Use, Avoid and Also written as, plus Keep exact and one Note.

## Storage

`ai_glossary_concepts` holds one row per meaning, with `key`, `note`, `keep_exact` and `case_sensitive`. `ai_glossary_terms` holds one row per language, with `locale`, `preferred`, `forbidden` and `aliases`. There are no copies of the note, flags or other languages on a term.

- A ban is a term with `preferred` null and Avoid set. Its first Avoid entry is what it goes by.
- `case_sensitive` shipped on main as a checkbox apart from `do_not_translate`, so customer rows can have either alone. It stays, null unless it differs from Keep exact: `Cart` with `case_sensitive` and Avoid `cart` still translates and still tells `Cart` from `cart`, and `Acme` with `do_not_translate` alone still ignores case. It has no control in the editor. The older term endpoints and `source_locale` files read and write it. Changing Keep exact in the editor or a new sheet goes back to Keep exact deciding, and asking for the value a row already has changes nothing.
- Local databases that ran earlier unreleased migrations can hold leftover columns on `ai_glossary_concepts`. No code reads or writes them, and all are nullable.

## Conversion

`2026_11_05_100000_normalize_ai_glossary_terms` renames the old table to `ai_glossary_terms_legacy`, unread, and fills the new one.

- Terms keep their old IDs. A translation that becomes a language of its own gets a new one, and so does a term moved to another language later through the older endpoint.
- `preferred` holds wording of any length that was there. The 255 limit is on edits and files.
- A row's own language wins over a translation another row held. A translation that disagrees stays as Also written as, so no wording goes missing.
- A language code the table cannot hold stops the conversion before anything changes, listing each glossary, term and code. Empty, too long and malformed codes were accepted before, and a person has to decide what they meant.
- MySQL commits each schema change alone, so a run that stopped partway picks up where it stopped.
- A rollback (`down`) restores the old table as it was converted, so everything written since is lost. Use it only to undo a deploy straight away.

## Concept rows API

`/mgmt/v1/{spaces/{space}|teams/{team}}/ai/glossaries/{glossary}/concept-rows`

```ts
{
  id: string              // concept id
  key: string | null
  keep_exact: boolean
  note: string | null
  languages: Record<code, {
    use: string | null    // null: a ban in this language
    avoid: string[]       // a ban goes by the first entry
    also: string[]
  }>
  coverage: Record<code, 'own' | 'fallback' | 'missing'>
}
```

`GET` takes `search`, `missing`, `page`, `per_page`. `meta` holds `read_only`, `default_locale`, `available_locales` and `facets: { total, locales, missing }`.

`POST` and `PATCH` take `{ key?, keep_exact?, note?, languages?: { <code>: { use?, avoid?, also? } }, remove_languages? }`. Omitted keys keep their value.

- `note` is at most 200 characters, and null clears it. Send it only when it changed: a longer older note fails validation if sent back.
- `use: null` clears Use, which leaves a ban if Avoid has entries.
- `bulk` takes `{ row_ids, action: 'delete' }` and returns `{ data: { deleted } }`.

Refusals: 409 `wording_conflict`, `key_conflict`, `stale_glossary` and `language_conflict`, and 422 `term_required` (neither Use nor Avoid), `use_avoided` (a Use its own Avoid would flag under the row's case, `*` included, with `locale`, `wording` and `avoid`), `last_language` and `language_invalid`. A wording conflict compares a language's Use, or a ban's first Avoid.

The older `…/terms` endpoints are a view of one language of a row, for the published SDK: `term` is Use or a ban's first Avoid, `translations` are the other languages with a Use, and locale keys come back normalized. Their rules are in the [management API docs](../../api/management-api.md).

## Text check

`POST …/glossary-check` with `{ text, source_locale, target_locale?, output?, scope? }` returns `{ terms, findings }`. `scope` is `glossary` or, in a space, `space`.

```ts
terms: {
  term: string            // the row's wording, or the Avoid wording the text used
  instruction: string     // the brief's line without the dash
  avoided?: true          // the text used only Avoid wording of this row
  required?: string
  do_not_translate?: true
  forbidden?: string[]    // what the output must not hold
  note?: string
  concept_id?: string
  row_id: string | null
  glossary_id: string | null
  glossary_name: string | null
  matches: { start: number, end: number, text: string }[] // UTF-16 offsets
}[]
findings: { type, term, expected?, found? }[] | null
```

`lookup_glossary` returns the same fields without `row_id`, `glossary_id`, `glossary_name` and `matches`.

## Brief and findings

```
Terms found in the source (de). Follow each line.
- "Bett": write "bed" in en
- "Acme": keep exactly as "Acme"
- "Comet": write "Bolt" in en
- "Sofa": write "couch" in en; never use "settee", "Couchen*"; note: ...
- "Verkäufer": never use; write "Fachpartner"
- "Billigbett": never use
A note is for your judgment: it applies only where it says so, and nothing checks it.
```

- `in <locale>` appears when translating. The never-use list is the output language's Avoid.
- A row with nothing for the output language has no line in the prompt. Its source wording is no rule there. With a note it reads `"cart": translate naturally; note: ...`, and `lookup_glossary` always answers that way. Identical lines appear once.
- Avoid wording of rows the text did not activate follows in one bounded line, at most 40 entries and 600 characters, bans first. It explains `*` only when a listed entry has one, and says when it is cut. Output is still checked against everything.
- Also written as counts as the term in matching, so required-wording and Keep exact checks trigger on it. It ignores case. Longest match stays, over all three kinds of wording.
- A snapshot of version 1 and a run's frozen `terms` keep the rules they were pinned under, all in `FrozenGlossaryRow`. Only such old policies read this class. New snapshots are version 2 with the exact or base language rule.
- `forbidden_variant.expected` is the row's Use in the output language, absent for a ban with none. `missing_term` stays agent-only.
- A `*` entry is always a wildcard, also as a ban's first entry.

## Where tasks meet the glossary

Code enforces scopes, versions, and exact Avoid and Keep exact checks. Missing Use wording is a hint for the agent. Notes are advice. Nothing here blocks saving a content draft or publishing it.

**Which language a change is checked in.** The destination decides, never the text.

| Destination | Language |
| --- | --- |
| Content | The entry's `language_iso`, on a new entry the one it is created in |
| Asset field `fields.<key>.<field>` | The key, `_default` being the space's default language |
| Data entry of a source with translatable dimensions | `dimensions.<key>` its key, `value` the `default_dimension_locale` |
| Other data entries, and blocks, folders and tags | The run's language, as they name none |

A run that names a translation (`source_locale` differs from `target_locale`) keeps that pair. So do inline rewrites and editor translations, whose run already holds their entry's language or an explicit pair: without a named locale, a run about one entry (`content_id`, a field chip) takes that entry's language instead of the space default. `propose_translation` and `propose_translation_metadata` add their true source and target as before.

Findings, `review.locales` and the provenance of applied rows all use this language, so one run over mixed entries checks each in its own under one pinned policy. The change's existing value is the source of a Keep exact check. An empty field has none, so only a translation checks it against what the run read. An SEO field need not hold every term of the page.

**What the model hears first.** `get_entry` adds Avoid wording for the entry's language to the terms its text activates, and `get_asset_library` lists it per language key. Both use the bounded line above.

**Agentic asset proposals.** `propose_asset_changes` returns `glossary_findings` on each proposed asset with `path`, `found` and `expected`, and `glossary_note` once. Only items this call wrote appear. The model is asked to correct once; a correction is not guaranteed. What stays flagged waits for a person, who gives a reason to accept it as it is.

**Background classification.** There is no draft and no person in between, so the job checks each generated value before saving:

- The prompt has a section per language it writes, in real locales: terms the hints (filename, folder, placement, existing values) activate, plus the bounded Avoid line. It says the rules decide wording, never what the image shows.
- Each value is checked in its own language under the run's pinned policy. `AiRunService` skips its check over the whole answer for these runs, as it has no one language.
- A value with Avoid wording is not saved. It goes to the report's `rejected` with the wording to write, the run's `result.glossary_findings` names it by `key`, and the status is `partial`, or `none` when nothing was usable. Other fields and tags still save, an editor's concurrent edit still wins, and with `overwrite` the old value stays.
- The finished toast warns when images have fields left open, with the existing `field_report` behind it.

This is one pass, by choice. A refused field stays empty, and classifying again fills it. A correction turn would mean a second vision call for each violation inside a job limited to 55 seconds. The cost is that a model which ignores the prompt leaves the field open for a person to rerun or fill.

Limits: an Avoid check matches exact wording and `*` forms, so a paraphrase passes. Classification has no source text, so it cannot tell whether a Keep exact name it did not write should have been there. Forced noncompliant responses use [FakeAiDriver](../../../tests/Support/FakeAiDriver.php); the real-provider samples below exercise ordinary compliance.

## Files

New sheet: `concept, keep_exact, note`, then per language `term:<l>, avoid:<l>, also:<l>`. Lists split on `;`, and `\;` is a literal one. A header needs `concept` or one `term:` or `avoid:` column. A row with neither Use nor Avoid in a new language is a row error. A ban exports with a blank `term:` and reads back unchanged. Old columns report once in `ignored_fields`.

`source_locale` files and TBX go through the same writer. A line or entry updates the row holding its first language's wording. Another of its languages finds a row only if that row has no wording in the first language. Otherwise it counts as new, and a wording it shares with that row is a row error, so an import cannot replace a Use it did not name.

## Checks

Current tree, 2026-11-04:

- Final pre-commit check, 2026-11-05: affected PHP groups listed below pass together, 570 tests and 5500 assertions. `bunx vitest run tests/js/components/ai-settings tests/js/lib tests/js/composables/useAssetAiClassification.test.ts`: 1019 pass. `bunx vue-tsc --noEmit`: clean. No further provider calls.
- Real OpenRouter `openai/gpt-6-luna`: 18 synthetic generations, confirmed cost $0.001334. Eight paired cases used the actual skill text, driver, brief and checker: six baselines had prohibited wording, none of eight guided outputs did. Two more calls ran the real CMS paths against isolated SQLite: multilingual image classification saved compliant English/German alt text, and a German selection rewrite inferred its entry language in an English-default space. Both integration tests passed, 14 assertions. No customer data or existing database was used. [Inputs, outputs, usage and limits](../../evidence/2026-11-04-glossary-provider-verification.json).
- A semantic limitation appeared in the real samples: banning `klimaneutral` led to `umweltfreundlich`, which passed the wording check while retaining an environmental claim. A short note removed the claims in two further guided samples. Notes remain advisory, and this small sample is not evidence of general editorial quality.
- Task integration, against FakeAiDriver and no real provider: `vendor/bin/phpunit tests/Feature/Ai` 395 tests, 3054 assertions; `tests/Unit/Services/Ai` 94; `tests/Feature/Mcp` 28; `tests/Feature/Mgmt/AssetClassificationTest.php` 33; `DataEntryTranslationStreamTest` 3; `ImportStreamingTest` 14; `tests/Feature/Documentation` 3. All pass on SQLite. The 10 new boundary tests fail with the old behaviour put back in a scratch copy.
- `vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation tests/Feature/Mgmt/ImportStreamingTest.php tests/Feature/Services/SpaceDatabaseMigratorTest.php`: 529 tests, 5301 assertions pass on SQLite.
- `bunx vue-tsc --noEmit`: clean. `bunx vitest run tests/js/components/ai-settings tests/js/lib`: 1017 pass. `bunx oxlint --deny-warnings` and `vendor/bin/pint --test` on the files changed last: clean.

Earlier on 2026-11-04, on this schema. The migration has not changed since, so these were not rerun:

- Disposable MySQL 26.10 database: a clean run, retries from 5 partial states and a rollback all passed.
- Rendered in headless Chromium, real `AiGlossaryDetail` with real CSS against a mock API on a scratch server, screenshots opened and looked at: four-term and large lists on desktop and phone, an inherited glossary with its export menu and sheet, a phone sheet with a long Use, a dirty sheet with Save and try, Try a text with findings on both widths, reopened after a save and with both scopes, and `use_avoided` on both widths.

Rendered last, the same way: at 1600px, the import dialog opened from an empty glossary with the Add a term sheet open. It sits above the sheet where they overlap, and Escape closes only the dialog. Below about 1600px without the app's sidebar, the sheet covers the empty state's Import button, so this path needs a wide screen.

## Benchmark from before normalization

Historic, taken on the old one-row-per-language table and not repeated on the new schema, so it says nothing about the current writer. 5000 rows x 6 languages, 2.62 MB of CSV, SQLite in memory, PHP 8.5, Apple Silicon, 512 MB limit.

| Step | Time | Peak |
| --- | --- | --- |
| Import CSV, all new | 11.5 s | 191 MB |
| Import CSV, unchanged | 11.2 s | 230 MB |
| Import CSV, all changed | 13.9 s | 276 MB |
| Export CSV | 2.4 s | 176 MB |
| Live policy, 30,000 terms | 1.8 s | 262 MB |
| Activate on a text with 200 hits | 0.7 s | 250 MB |

## Not exercised

- The full stack together. The rendered checks used a mock API, and the PHP tests have no browser. Nobody has yet used the new editor logged in against a real backend on the converted schema.
- A representative editorial evaluation across models, real customer content and repeated samples. The bounded real-provider checks above establish behavior only for those synthetic inputs; forced violation/rejection paths still use a fake provider.
- This migration on existing local or production data. It has run on tests and on the disposable MySQL database only, and it is not applied locally. See [STATUS](../../STATUS.md) for the deploy step.
- MySQL's behaviour on the writer, the text check and imports beyond the conversion. Those tests ran on SQLite.
