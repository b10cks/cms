---
description: "Data sets in depth: what they are for, when to use one instead of content, simple and structured entries, dimensions, translation, and how to feed dropdowns and your website from them."
---

# Data sets

**Data sets** hold the lists your project relies on but which are not pages: categories, countries, departments, opening hours, shirt sizes, shipping rates, office locations, feature flags.

## Why they exist

Without data sets, a list like "our twelve product categories" ends up typed into four places. Once in a dropdown a developer hardcoded into a content type. Once in the website's own code for the filter bar. Once in a spreadsheet somebody maintains. Once in the footer, by hand.

Then a category is renamed, three of the four are updated, and the fourth quietly disagrees for a year.

A data set makes that list a single thing that exists once:

- **Dropdowns in the editor read from it.** Add a category here and it appears in every field that uses this list, immediately, without anybody editing a content type.
- **Your website reads from it too**, through the same list, so the filter bar and the editor can no longer drift apart.
- **Everyone who may edit content can maintain it.** No deployment, no developer, no ticket.

::: tip Highlight
The same list feeds the editing interface and the live website. That is the part most systems miss: they let you define dropdown options, but those options stay locked inside the CMS, so the website still hardcodes its own copy.
:::

## When to use a data set, and when not to

| Your case | Use |
| --- | --- |
| A list of short values people pick from: categories, countries, statuses | **Data set** |
| Labels or snippets your website shows but nobody should have to redeploy to change | **Data set** |
| Something with its own page, its own address, its own images and text | **Content** ([Content guide](content.md)) |
| A handful of options that will never be used anywhere else | A plain option list on the field itself |

The rough rule: if it needs a URL, it is content. If it is a value people choose or a label your site prints, it is a data set.

## Creating a data set

**Name** is what people read in the app. **Technical name**, also called the slug, is what your website uses to fetch the list. It may contain lowercase letters, numbers, and hyphens. The list view has a button that copies the ready-made address to your clipboard, so a developer can be handed the exact endpoint without spelling anything out.

**Description** answers "what is this for and who owns it" for the colleague who finds it in two years.

### Entry values: simple or structured

This is the most consequential choice, and you can change it later.

**Simple text.** Each entry is a key and a single text value. Perfect for flat lists:

| Key | Value |
| --- | --- |
| `de` | Germany |
| `at` | Austria |
| `ch` | Switzerland |

**Structured fields.** Each entry carries several typed fields that you define. The same country list can then hold everything the website actually needs:

| Key | name | callingCode | vatRate | inEu |
| --- | --- | --- | --- | --- |
| `de` | Germany | +49 | 19 | yes |
| `ch` | Switzerland | +41 | 7.7 | no |

You define this structure as a list of fields, each with a name, a key, a type, and whether it is required. The available types are **text**, **textarea**, **number**, **boolean**, **date**, **single option**, and **multiple options**. For the two option types you also supply the choices, written as `value:Label` pairs.

Structured entries are what turn a data set from a dropdown list into a small database your website can render properly: a location list with address, opening hours, and coordinates, or a pricing table with a value per tier.

::: warning Switching back to simple text
If a data set already has structured fields and you save it as simple text, the structure is removed. Existing entries keep their data, but it is shown as raw text from then on. Going the other way, adding a structure to a list that was simple, is safe: existing plain values stay valid and editable, and the editor shows you what the previous plain value was.
:::

### Dimensions

**Dimensions** add a second axis. Each entry keeps its default value plus one value per dimension. Languages are the most common use, regions the second:

| Key | Default | `de` | `fr` |
| --- | --- | --- | --- |
| `greeting` | Hello | Hallo | Bonjour |
| `cta` | Learn more | Mehr erfahren | En savoir plus |

Your website asks for the dimension it needs and gets the matching value, falling back to the default whenever a dimension has nothing. One list, many markets, no duplication.

**Are dimensions translatable** tells b10cks that your dimensions are languages rather than something else, such as customer tiers. Switching it on unlocks AI translation for the set. You also pick a **default dimension locale**, which is the dimension AI treats as the source it translates from.

### Cache duration

How long, in seconds, your website may reuse a copy of this list before asking b10cks again. Higher means faster pages and fewer requests. Lower means changes show up sooner. Zero switches reuse off, and every request goes to b10cks.

For lists that change a few times a year, such as countries, a long duration is free performance. For something editors adjust during the day, keep it short.

### Availability for the API

Whether the list is published to your website at all. A set that is switched off still works fully inside b10cks, including as the source of dropdown options. Use it for internal lists that have no business being public, and for a list still being prepared.

## Filling in entries

Every entry has a **key**, a **value** (or the structured fields you defined), and a value per dimension if the set has any. The key is the stable technical name your website matches against, such as `de`. It should never change once your site relies on it. The value is what people read.

Two ways to edit, switched with a toggle:

- **Single edit** gives you a form for one entry at a time, with proper inputs for each type of structured field. Good for careful work and for long values.
- **Grid edit** gives you a spreadsheet of all entries and all dimensions at once, saving automatically as you type. Good for filling in a hundred rows in one sitting. Values can span several lines.

Entries can be searched by key or value, paged through, and deleted. Each one can also be switched **inactive**, which keeps it in the list but removes it from the choices people can pick. That is how you retire a category without breaking the pages that already reference it.

### Translating with AI

If dimensions are marked translatable and AI is available in your space, **Translate missing entries** fills the gaps for a whole dimension in one batch, translating from your default dimension and reporting progress as it goes. It only fills what is missing, so translations you refined by hand are left alone.

## Import and export

**Export** downloads every entry as JSON, CSV, Excel, or YAML. CSV and Excel open in any spreadsheet program, which is the practical way to hand a list to somebody who does not use b10cks.

**Import** uploads a file to create or update many entries at once. You choose how existing entries should be treated, and afterwards a summary lists what was created, changed, and skipped.

The obvious workflow: export, send the file to whoever owns the list, import what comes back.

## Using a data set

### In dropdown fields

When your team defines an **Option** or **Options** field on a block, or an option column inside a table field, they can set its source to a data set instead of typing choices into the field. The field then shows your entries as the available choices, and the stored value is the entry's key.

Maintain the list once here, and every field pointing at it stays correct. Adding a category is a one-minute job for an editor rather than a schema change. See [Fields](../concepts/fields.md#option).

### In your website

Your developers fetch the entries directly:

```
GET /api/v1/datasources/{slug}/entries?token=…
GET /api/v1/datasources/{slug}/entries?token=…&dimension=de
```

Entries come back with their key, value, and last-changed time. Adding `dimension` returns the value for that dimension, falling back to the default. For a set with structured fields the value is an object with your fields in it. Responses honour the cache duration you set, and paging is supported for long lists. The details are in [Data sources](../concepts/data-sources.md#data-api).

## Good to know

- Renaming a **name** is cosmetic. Changing a **key** or a **slug** breaks whatever refers to it, so treat both as permanent once something uses them.
- Deleting a data set deletes all its entries and cannot be undone. Fields pointing at it lose their choices.
- Entry changes appear in the [audit log](audit-logs.md) like everything else, so a list that mysteriously changed has an author.
