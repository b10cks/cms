---
description: "The CSV and XLSX format for editing a b10cks glossary in a spreadsheet."
---

# Glossary sheets

This page is the file format. For what a glossary does and when to use one, see [Skills, knowledge and glossary](../ui/ai-knowledge.md#glossaries).

A glossary can be edited in Excel or any other spreadsheet. Download the current glossary or a template from the glossary page, change it, and upload it through the same dialog. It applies straight away, with no review step.

- Rows you leave out stay as they are.
- A blank cell keeps its stored value.
- Nothing is removed by an import. Remove rows and languages in the glossary editor.
- Each import writes one audit log entry with who ran it, the file name and the counts. An identical file changes nothing.

## Columns

There is one row per meaning. Column order and upper or lower case do not matter, and `de_AT` means the same as `de-at`. Only one `term:` or `avoid:` column is required, or `concept` to change rows that already exist. A file with `term:de` and `term:en` and nothing else works.

| Column | What it holds |
| --- | --- |
| `concept` | A stable name you choose, like `cloud-bed`. Letters, digits and `.` `_` `:` `-`. It finds the row again when its wording changes. |
| `keep_exact` | `yes` for a name that stays as written, in exact case, like a product name. When translating, a target language's own term still wins. The source wording stays only when the target language has none. |
| `note` | One short note for the whole row, at most 200 characters. The AI reads it whenever the row comes up, and nothing checks that it was followed. Longer material belongs in a knowledge document. |

These repeat for each language, with its code after the colon:

| Column | What it holds |
| --- | --- |
| `term:de` | The wording to write in this language. |
| `avoid:de` | Wording never to write in this language. The row's term is the replacement. |
| `also:de` | How a source text may also spell this term, such as inflected forms. It counts as the term itself. |

`avoid` and `also` hold lists separated by `;`. A wording that contains a semicolon writes it as `\;`.

A region's language replaces its base language whole. With `term:en` and `avoid:en` holding `lift`, and `term:en-GB` holding `lift`, British English may write `lift`. A region with no columns of its own in that row uses the base language, `avoid` included.

A `term` its own `avoid` would flag is an error on that row: `Cart` with `cart` unless `keep_exact` is on, and `Händler` with `Händler*` always.

A row with `avoid` and no `term` in a language is a ban there. Findings on it have no replacement until someone adds a term. A row with neither is an error on that row.

An `avoid` entry can end in `*` to cover longer forms. `klimaneutral*` matches `klimaneutrale` and `klimaneutralen`, and `Händler*` matches `Händlern`. The `*` stands for the rest of a word that starts with the text before it, and the start of the word must still match. In an entry of several words, each word can end in `*`. A `*` anywhere else is plain text. The `*` survives an export and an import.

## How a row finds its concept

1. `concept` decides when that key exists. A new key on a row whose wording has no key yet gives it that key.
2. Otherwise the row matches on its wording in the space's default language, ignoring case: the term, or the first `avoid` entry of a ban. A team glossary uses the first `term:` or `avoid:` column.
3. If that language is blank in the row, or the matching concept has no wording in it yet, the row matches on its other languages.
4. No match creates a new concept.

A wording that belongs to another concept is an error on that row. Nothing moves on its own. Two rows in a file that reach the same concept are an error too, and the first one applies.

## Errors and warnings

A bad row does not stop the import. The result lists each row error with its row number, column and message, and the dialog can copy them or download them as CSV. Row numbers are the ones your spreadsheet shows, with the header as row 1.

- A column b10cks does not know is reported once and ignored. That includes the columns of earlier sheets, like `category`, `status` or `definition:de`.
- A language the space has not enabled is stored and reported once. It is not used until the space enables it.

## Files

- CSV can use `,`, `;` or a tab, and should be UTF-8. Downloads start with a byte order mark so Excel reads umlauts correctly. If Excel puts a downloaded CSV into one column, use the XLSX file.
- XLSX reads the first sheet. Downloads also hold a Help sheet. A formula counts as the result Excel saved.
- A file can be up to 10 MB. 5000 rows in 6 languages with Use, Avoid, Also written as and a note fit in about 3 MB of CSV.
- A cell that starts with `=`, `+`, `-` or `@` is written with a leading `'` so a spreadsheet does not run it as a formula. That quote is removed again on import.

## Older formats

A header with `source_locale` is the earlier term-based format, and it keeps importing: each line is read into a row and written the same way. Its `case_sensitive` column is not read, since case follows `do_not_translate`. A language it names in `translation:<locale>` changes that language of the row, and its `forbidden` wording reaches each language the line names. TBX imports and exports too: in each language the first preferred term is the row's term, deprecated terms are `avoid`, and admitted terms and any further preferred term are `also`. A line or entry updates the row holding its first language's wording. Another of its languages finds a row only if that row has no wording in the first language, otherwise the shared wording is a row error and nothing changes on that row. A note over 200 characters is a row error too, unless it is the note that row already holds. Neither writes an audit log entry.
