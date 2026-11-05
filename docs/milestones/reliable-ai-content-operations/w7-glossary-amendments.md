# W7 glossary amendments

Implements [brief](brief.md) revision 2. Integration branch: `feat/ai-content-operations`. The streaming import branch is merged into it, so `RespondsWithImportRun` and the generator pattern are available.

The bar: a glossary editor that a 2027 terminology manager would pick over a spreadsheet. Nothing here may name or special-case a customer.

## Settled, do not reopen

- b10cks sets the format. The sheet is direct apply, one row per concept. Missing rows stay, blank cells keep their value, removal happens in the editor, each import records a revision.
- Markdown import and draft review stay and get no new features.
- Status and audience belong to the concept, and a language can override them. Storage stays per term: no data migration, the writer writes through. `category` is the only new column.
- A language's own wording beats protected. Protected means fixed per language, and the source wording stays only when the target language has none.
- Missing required wording is a hint: no reason, does not stop bulk accept. Only a term's own wording triggers the required-wording and protected checks. Aliases add the term to the prompt and to lookup results.
- Exact banned wording and protected names stay deterministic, with correction or a reasoned override.
- Semantic review receives the output language's banned terms.
- The meta button fills its fields and shows banned-wording findings inline, without blocking.
- Space languages drive pickers and run languages. Terms in languages the space has not enabled stay stored and show as unused. Team glossaries can use any language.
- D10 stays deferred.

## Sheet

Columns, in this order. Locale columns repeat per language.

`concept, category, status, audience, case_sensitive, protected, term:<l>, aliases:<l>, banned:<l>, definition:<l>, notes:<l>, status:<l>, audience:<l>`

- `concept` is the stable key. Without one, a row matches on the term in the space's default language (team glossary: the first `term:` column).
- Lists (`aliases`, `banned`, `audience`) separate on `;`. `status` is `preferred`, `prohibited` or `contextual`. `status:<l>` and `audience:<l>` are the optional per-language overrides.
- A header with `source_locale` is the old format and keeps importing. TBX keeps working.
- A wording that belongs to another concept is a row error. Nothing moves silently.

## Tasks

| Task | Outcome | Evidence | Depends on |
| --- | --- | --- | --- |
| T1 enforcement | Checker, prompt, lookup, semantic and acceptance follow the settled rules; meta button and reviewer UI show them | `w7-enforcement.md` | |
| T2 concept API | Concept row read and write API, `category`, try-a-text endpoint | `w7-concept-api.md` | |
| T3 sheet | Full-field CSV/XLSX import and export, template, shared dialog with streaming | `w7-sheet.md` | T2 |
| T4 editor | Concept-first editor replaces the term table and dialog | `w7-editor.md` | T2, T3 |
| T5 prototype | One-off import of the reference file in a local space, rendered check of the whole flow | `w7-prototype.md` | T1 to T4 |

## Reserved numbers

- Management migration `2026_11_04_100000_add_category_to_ai_glossary_concepts` (T2).
- No space migration is expected. Space prefix `0000_00_00_000028` is reserved for T1 if one turns out necessary.
