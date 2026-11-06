# W7 prototype evidence

The reference glossary went through the product over real HTTP and a real browser session, on the local `Test` space (default `en`; enabled `en`, `de`, `de-at`, `fr`, `fr-ch`). A throwaway script outside the repo converted the file's embedded JSON into the sheet. Neither the file nor the script is committed.

## Import

| Step | Result |
| --- | --- |
| First upload, streaming | 184 added, 17 row errors, one revision, 0.9 s |
| After giving protected names their wording | 17 added, 184 unchanged, one revision |
| Same file again | 201 unchanged, no revision |
| CSV export, then import | 201 unchanged, no revision |
| XLSX export, then import | 201 unchanged, no revision |

The 17 row errors were correct: those rows had a definition in a language with no wording. `nl`, `it` and `es` were reported once as stored but not used, and show that way in the editor.

## Enforcement, through `glossary-check`

| Case | Result |
| --- | --- |
| German brand to English, correct market name | No finding |
| German brand kept on an English page | Blocking, expects the market name |
| Common aliases such as `Marketing` and `Partner` | Activate terms, no finding |
| English plural of a required term | Hint only |
| Banned wording, German and English | Blocking, in the output's language only |

## Editor, rendered in the real app

Checked in headless Chromium with a real session against the real backend: list at 1440 light and dark, list and sheet at 390 dark, search and the `/` key, URL state, a status filter, arrow and Enter navigation, focus returning to the row, save with Cmd+S, duplicate wording keeping the typed text, the unsaved guard, Try a text with highlighted aliases, sheet upload with per-row errors, and a sheet revision in the panel. No page overflow at 390. No page errors.

One fix came out of this: the menu listed "Import Markdown" above a vague "Import". The sheet now comes first as "Import spreadsheet".

## Not exercised

- **A real provider.** This means Fill missing with AI, the meta button's run, and how well semantic review finds inflected and paraphrased banned wording. No AI calls were made.
- **Real Excel or Numbers,** real phones and a screen reader.
- **Production or MySQL load.** The import ran on local MySQL with 201 concepts; the 5000-concept benchmarks are from SQLite.
- **German copy in the real app.** T4 viewed it against a fake API.

## Editorial work left for the glossary's owners

The import is faithful, not finished. `Interne Synonyme` mixes banned synonyms with replacements, so the `banned` columns are empty apart from each banned term's own wording. Rules inside notes, such as those for `Top-On` and `Lordose`, reach the AI as notes but not the checker.
