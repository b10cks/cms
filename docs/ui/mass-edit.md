---
description: "Change one field across hundreds of pages at once in a spreadsheet-style table, with a column per language, filters, AI translation, and export for agencies."
---

# Mass Edit

Some jobs simply don't fit the page editor. Rewriting every search-engine description in the space. Filling in the German title for 200 products. Finding all the pages where the teaser text is still empty. Opening each page one at a time is the wrong tool for that.

**Mass Edit** turns that work into a table. You choose which fields you care about, and every page that has those fields appears as rows you can type into, with one column per language.

You find it in the menu on the left under **Mass Edit**. Anybody who may view content can open it. Typing and saving needs permission to edit content, and *Save and publish* needs permission to publish.

## Setting up the table in three steps

The table stays empty until you tell it what to load. That is deliberate: a space can hold thousands of pages, and loading all of them blindly would help nobody.

1. **Choose the fields.** The field selector lists every field in your space that can hold text, collected from all your content types, including fields that only ever appear inside page sections. Each field is shown with its label and its technical name next to it, so a `title` in a hero section and a `title` on a page end up as the same column.
2. **Choose the languages.** All languages of the space are selected to begin with. Remove the ones you don't need so the table stays narrow enough to read. The original language always comes first and is marked **Source**.
3. **Narrow the list** with the filters. This step is optional, but it is the difference between 40 rows and 4,000.

### Which fields you can pick

A field shows up when its type can actually hold translatable text: **text, longer text, markdown, rich text, number, date, meta, and table**. Images, references, dropdowns, switches, and links are not editable here, because they describe structure rather than wording.

Two kinds of field end up in the list:

- **Translatable fields**, which you can edit in every language column.
- **Fields marked "source only"**, which are of a supported type but which your content type does not translate. You can see and edit them in the source column, while the language columns show a small lock. That is not a restriction b10cks invents: a field that isn't translatable always delivers the original value to every language anyway ([Internationalization](../concepts/internationalization.md)).

Some fields become more than one row, because that is genuinely how they get translated:

| Field type | What appears in the table |
| --- | --- |
| **Meta** | One row each for the title, the description, and the two social-media variants |
| **Table** | One row per text cell |
| **Rich text** | One row holding the formatted text as HTML. The tags belong to the text, so leave them intact and translate around them. |

## Filtering

The filter bar works like everywhere else in the app. Pick something to filter on, pick how to compare it, type a value.

- **Name, slug, full slug**: contains, starts with, ends with, is exactly
- **Content type** and **status** (published or draft)
- **Created or last changed**: before, after
- **The value of any selected field**: contains, starts with, ends with, is exactly, plus **is empty** and **is not empty**

That last pair does most of the heavy lifting. Select the meta field, filter for *meta title is empty*, and your entire to-do list is on one screen.

## Typing in the table

Rows are grouped per page. The first row of each group carries the page's name and full address, and every row says which field it belongs to. Fields from nested sections show their path, so you can tell the hero headline from the teaser headline.

- **Cells you changed get an amber outline**, so your own trail stays visible in a long table.
- **<kbd>Escape</kbd>** puts the cell you are in back to its saved value.
- **<kbd>↑</kbd> and <kbd>↓</kbd>** jump to the same column one row up or down once the cursor is at the start or end of the text, so a whole column can be worked through without the mouse.
- Cells grow with their content, and you can drag them taller.

::: tip Highlight
Your unsaved edits survive paging, filtering, and AI translation runs. You can work through 400 rows across ten pages, review everything, and commit it all with one Save. Nothing is written until you decide it is.
:::

**Your changes survive paging.** Go to page 4, type there, come back to page 1, and everything is still waiting. One save writes all of it. The number on the **Save** button always shows how many changes are actually open, not just the ones currently on screen. **Discard** throws all of them away.

There is one situation where the app interrupts you: removing a field or a language while you have unsaved changes hides those cells but does not throw the changes away, so you are asked whether you want to discard them first.

## Saving

- **Save** stores everything as drafts. Nothing goes live, exactly like saving in the page editor.
- **Save and publish**, in the button's menu, saves and immediately publishes the affected pages.

Three things are worth knowing:

- **Missing translations are created for you.** Typing into a language column for a page that has no version in that language yet creates that version.
- **Emptying a cell means emptying the value.** A cleared cell is saved as empty rather than skipped, which is how you remove a wrong translation from here.
- **Big saves are sent in portions.** Every page and language writes its own version, so a large save is split into chunks and shows its progress as `saved / total`. Pages that fail keep their amber outline and stay editable, while everything that went through is cleared. A slow connection can no longer lose half your work.

Everything written here goes through the same machinery as normal editing: versions are created, the history and the audit log record who changed what, and validation applies as usual. If some pages fail, the result tells you how many were saved and how many had errors.

## Translating with AI

If your space has AI configured under **Settings → AI**, the **Translate with AI** button fills the language columns for you. The button's menu lets you pick a different AI configuration than the default one.

What it does:

- It works on **your whole current selection**, not just the visible page of the table. Your fields, languages, and filters define the job.
- It translates cells that are **empty**, and cells whose **source text you just changed** in this session, because the existing translation is now out of date. Translations you already have are left alone.
- It skips fields marked source only.
- Results stream into the table as they arrive, in batches, with a live counter.

Translations arrive as **unsaved changes**, exactly like something you typed yourself. Nothing is written until you press Save, so you get to read and correct first. If your selection is too large for one run, the app tells you to narrow the filters and repeat.

## Export and import

The buttons in the header hand the same selection to the common exchange formats: **XLIFF, CSV, Excel, JSON, or YAML**. XLIFF is what professional translation tools expect. CSV and Excel open in any spreadsheet program. JSON and YAML are for developers.

- **Export** respects your fields, languages, and filters, so what you get is what you were looking at. Rows whose cells are still empty are included on purpose, because the gaps are usually the whole point of sending the file.
- **Import** applies a returned file. Choose whether the content arrives as drafts for review or is published straight away, and whether missing languages should be created. A summary shows exactly what changed.

A file exported from Mass Edit can travel back and forth. It has one column per language **including the source**, so an agency can edit the original text as well, and a cell somebody blanks out is imported as a deliberate clearing. This differs slightly from the export in the page editor, which gives translators a read-only source column.

One exception: **XLIFF treats the source text as read-only by design**, so changes an agency makes to the original will not come back through it. Use CSV, Excel, JSON, or YAML when the source text should be editable outside the app.

Apart from that it is the same import and export as in the page editor, so a job started here can be finished there and the other way around.

## Which tool for which job

| What you are doing | Best tool |
| --- | --- |
| Writing or restructuring one page | [Content editor](content.md) |
| Translating one page carefully, in context | The editor in [localization mode](content.md#translating-content) |
| The same field across many pages, such as SEO sweeps or missing translations | **Mass Edit** |
| Handing work to an external agency | Export from Mass Edit, import when it comes back |
| Moving or restructuring many pages | [Canvas](canvas.md) |

## Good to know

- Only **original entries** are listed, one row group per piece of content with its languages as columns. You never edit a translation here as if it were a page of its own.
- Pages whose content type has none of the selected fields simply don't appear.
- The row order is stable, so paging never skips or repeats a page.
- Everything here can be scripted as well. The table runs on the `mass-edit/fields`, `mass-edit/rows`, and `contents/export` endpoints of the [Management API](../api/management-api.md), and a single write call takes at most 100 entries.
