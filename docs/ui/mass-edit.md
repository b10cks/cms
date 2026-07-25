---
description: 'Edit one field across hundreds of entries at once: a spreadsheet-style grid with language columns, filters, AI translation, and export/import.'
---

# Mass Edit

Some jobs don't fit the page editor. Rewriting every meta description in the space, filling in the German title for 200 products, finding all the entries where the teaser is still empty — opening each page one by one is the wrong tool.

**Mass Edit** turns that work into a spreadsheet: you pick the fields you care about, and every entry that has them shows up as rows in an editable grid, with one column per language.

You'll find it in the space sidebar under **Mass Edit**. Anyone who can view content can open it; editing and saving needs content editing rights, and _Save & publish_ needs publishing rights.

## The three-step setup

The grid stays empty until you tell it what to load.

1. **Pick fields.** The field selector lists every text-carrying field in your space, gathered from all your content types — including fields that only ever appear _inside_ nested blocks. Fields are listed by their label with the technical key next to it, so `title` in a hero block and `title` on a page are the same column.
2. **Pick languages.** All space languages are selected by default; drop the ones you don't need to keep the grid narrow. The source language is always shown first and marked **Source**.
3. **Narrow it down** with the search filter (optional, but the difference between 40 rows and 4,000).

### Which fields show up

Fields appear when their type can hold translatable content: **text, textarea, markdown, rich text, number, date, meta, and table**. Assets, references, options, booleans and link fields aren't editable here — they're structure, not prose.

Two flavours of field end up in the list:

- **Translatable fields** — editable in every language column.
- **Fields marked _source only_** — supported types that your content type does _not_ mark as translatable. They're useful to see and edit, but only in the source column; the language columns show a lock, because a non-translatable field always delivers the canonical value anyway ([Internationalization](../concepts/internationalization.md)).

Some fields expand into several rows, because that's how they're actually translated:

| Field type    | What you get in the grid                                                  |
| ------------- | ------------------------------------------------------------------------- |
| **Meta**      | One row each for title, description, OG title, OG description             |
| **Table**     | One row per text cell                                                     |
| **Rich text** | One row holding the formatted text as HTML — tags stay, so keep them intact |

## Filtering

The filter bar works like everywhere else in the app — pick a field, an operator, a value:

- **Name, slug, full slug** — contains, starts with, ends with, equals
- **Content type** and **status** (published / draft)
- **Created at / updated at** — before, after
- **The value of any selected field** — contains, starts with, ends with, equals, plus **is empty** and **is not empty**

That last one is the workhorse: select `meta`, filter _Meta title is empty_, and you have your entire to-do list on one screen.

## Editing in the grid

Rows are grouped per entry: the first row of each entry carries its name and full slug, and every row names the field it belongs to (nested block fields show their path, so you can tell the hero headline from the teaser headline).

- **Edited cells get an amber outline** so you can see your own trail across a long grid.
- **Escape** reverts the cell you're in back to its stored value.
- **↑ / ↓** jump to the same column in the row above or below once the cursor reaches the start or end of the text — you can tab down a whole column without touching the mouse.
- Cells grow with their content and can be dragged taller.

**Your edits survive paging.** Move to page 4, edit there, come back — everything is still pending, and one save writes all of it. The counter in the **Save** button always shows the true number of open changes, not just the ones on screen. **Discard** throws them all away.

Changing the field selection while you have unsaved edits is the one case the app asks about: dropping a field hides its cells but does _not_ drop the pending changes, so you're offered the choice to discard first.

## Saving

- **Save** stores everything as drafts — nothing goes live, exactly like saving in the editor.
- **Save & publish** (in the button's menu) saves and publishes the affected entries in one go.

Two details worth knowing:

- **Missing translations are created for you.** Typing into a language column of an entry that has no version in that language yet creates one.
- **Clearing a cell means clearing the value.** An emptied cell is saved as empty, not skipped — that's how you undo a wrong translation from here.

Every write goes through the normal content pipeline: versions are created, history and audit logs record who changed what, and validation applies as usual. If some entries fail, the result tells you how many saved and how many errored.

## Translating with AI

With an AI configuration set up for the space (**Settings → AI**), **Translate with AI** fills the language columns for you. Use the button's menu to pick a different configuration than the default.

What it does:

- Works on **the whole current selection**, not just the visible page — fields, languages, and filters define the job.
- Translates cells that are **empty**, or whose **source you just edited** in this session (the existing translation is stale). Cells you've already translated are left alone.
- Skips _source only_ fields.
- Streams results into the grid as they arrive, in batches, with a live `applied / total` counter.

Translations land as **pending edits** — nothing is written until you press Save, so you get to review and fix first. If the selection is too large for one run, you'll be told to narrow the filters and repeat.

## Export and import

The header buttons hand the same selection to the translation exchange formats — **XLIFF, CSV, Excel, JSON, or YAML**:

- **Export** respects your fields, languages, and filters: what you exported is what you were looking at, including rows whose cells are still empty (that's usually the point — the translator needs to see the gaps).
- **Import** applies a returned file back, as drafts for review or published immediately, optionally creating language versions that don't exist yet. A summary shows exactly what changed.

This is the same import/export used from the content editor, so an agency workflow started here can be finished there and vice versa.

## When to use what

| Situation                                                             | Best tool                                        |
| --------------------------------------------------------------------- | ------------------------------------------------ |
| Writing or restructuring one page                                     | [Content editor](content.md)                     |
| Translating one page carefully, in context                            | Editor in [localization mode](content.md#translating-content) |
| The same field across many pages — SEO sweeps, missing translations   | **Mass Edit**                                    |
| Handing work to an external agency                                    | Export from Mass Edit, import when it comes back |
| Moving or restructuring many pages                                    | [Canvas](canvas.md)                              |

## Good to know

- Only **canonical entries** are listed — one row group per piece of content, with its languages as columns. You never edit a translation "as its own page" here.
- Entries whose content type doesn't have any of the selected fields simply aren't listed.
- Rows are ordered stably, so paging never skips or repeats an entry.
- Everything you do here is scriptable too — the grid runs on the `mass-edit/fields`, `mass-edit/rows` and `contents/export` endpoints of the [Management API](../api/management-api.md).
