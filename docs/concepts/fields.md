---
description: "All b10cks field types with their options, validation rules, and conditional display."
---

# Fields

Fields are the building blocks of every block schema. When you add a field to a block, you pick one of 19 types — from a simple text line to a nested block list — and configure how it behaves. This page is the complete reference: every type, every option, and the JSON your frontend receives.

New to content modeling? Skim the [type picker](#which-field-type-should-i-use) first, then dive into the types you actually need.

## Which field type should I use?

| You want to store… | Use |
| --- | --- |
| A short line of text (headline, label) | [Text](#text) |
| Longer plain text | [Textarea](#textarea) |
| Formatted text with headings, lists, links | [Richtext](#richtext) |
| Markdown source | [Markdown](#markdown) |
| A number | [Number](#number) |
| Yes/no | [Boolean](#boolean) |
| One choice from a list | [Option](#option) |
| Several choices from a list | [Options](#options) |
| A date and/or time | [Date](#date) |
| An image or file | [Asset](#asset) |
| A gallery / several files | [Multi assets](#multi-assets) |
| A link (page, URL, or email) | [Link](#link) |
| A pointer to other content entries | [References](#references) |
| Nested components | [Blocks](#blocks) |
| SEO & social metadata | [Meta](#meta) |
| Tabular data | [Table](#table) |
| An icon | [Icon](#icon) |
| A map coordinate | [Geo](#geo) |
| A price in one or more currencies | [Price](#price) |

## Settings every field has

| Setting | What it does |
| --- | --- |
| **Name** | The label editors see |
| **Description** | Help text shown under the field |
| **Required** | The entry can't be saved without a value |
| **Translatable** | The field gets a separate value per language ([i18n](internationalization.md)) — only on types where translation makes sense |
| **Indexable** | The value is included in full-text search |
| **Default** | Pre-filled value for new instances — defaults are applied whenever an editor creates content or inserts the block |
| **Conditions** | Show the field only when other fields match rules (below) |
| **Validation** | Extra rules: lengths, item counts, patterns (below) |

### Conditional fields

Any field can be shown or hidden based on the values of *other fields in the same block* — e.g. only show the `videoUrl` field when `layout` is set to `video`:

```json
{
  "mode": "all",
  "rules": [
    { "field": "layout", "operator": "equals", "value": "video" },
    { "field": "videos", "operator": "is_not_empty" }
  ]
}
```

`mode: "all"` requires every rule to match, `"any"` requires at least one. Operators: `equals`, `not_equals`, `in`, `not_in`, `is_empty`, `is_not_empty`, `gt`, `gte`, `lt`, `lte`, `contains`.

### Validation

Depending on the type: `min`/`max` (numbers, dates, item counts), `min_length`/`max_length` (strings), `min_items`/`max_items` (lists), `pattern` (regular expression), `allowed_values`.

---

## Text-like fields

### Text

A single line of plain text.

| Option | Effect |
| --- | --- |
| `translatable` | Per-language values |
| `min_length` / `max_length` / `pattern` | Validation |

Delivers: `"headline": "Hello world"`

### Textarea

Multi-line plain text (no formatting).

| Option | Effect |
| --- | --- |
| `translatable` | Per-language values |
| `min_length` / `max_length` | Validation |

Delivers a plain string with newlines.

### Markdown

Markdown source with a dedicated editor and preview. Delivered as the raw Markdown string — your frontend renders it.

| Option | Effect |
| --- | --- |
| `translatable` | Per-language values |

### Richtext

Structured rich text with a full-featured editor. Stored as a ProseMirror-style JSON document and rendered in your frontend with [`@b10cks/richtext`](../guides/rich-text.md) — never as raw HTML, so output is safe and fully under your control.

This is the most configurable field type. Each option shapes what editors can do:

| Option | Effect |
| --- | --- |
| `translatable` | Per-language documents |
| `heading_levels` | Which of `h1`–`h6` / `p` the editor offers |
| `features` | Per-feature toggles: `bold`, `italic`, `underline`, `strike`, `code`, `heading`, `bulletList`, `orderedList`, `blockquote`, `codeBlock`, `horizontalRule`, `link`, `internalLink`, `table`. Disabling a feature removes the capability entirely — not just the toolbar button. Pasted content (including from Word/Google Docs) is cleaned to the enabled set. |
| `html_classes` | Named text styles editors can apply (e.g. "Highlight"); rendered as `<span class="…">` — you define the CSS |
| `list_styles` | Named list styles (e.g. "Checklist"); rendered as a `class` on `<ul>`/`<ol>`, limited to bullet, ordered, or both |
| `placeholders` | Placeholder tokens like `{companyName}` that your frontend resolves at render time |

External and internal links share one unified link editor; internal links store the target entry's ID so they survive slug changes ([rendering guide](../guides/rich-text.md#internal-links)).

---

## Choice & scalar fields

### Number

A whole or decimal number.

| Option | Effect |
| --- | --- |
| `min` / `max` | Numeric bounds |

### Boolean

An on/off switch.

| Option | Effect |
| --- | --- |
| `show_inline` | Renders as a compact inline toggle in the editor |
| `default` | Start on or off |

### Option

Pick **one** value from a list.

| Option | Effect |
| --- | --- |
| `source` | `self` — define name/value pairs on the field; `datasource` — use a [data source](data-sources.md) as the list, managed centrally |
| `options` | The name/value pairs (when `source: self`) |
| `data_source_id` | The data source (when `source: datasource`) |
| `exclude_empty` | Remove the empty choice, forcing a selection |
| `default` | Pre-selected value |

Delivers the selected `value` string.

### Options

Pick **several** values from the same kinds of lists.

| Option | Effect |
| --- | --- |
| `source` / `options` / `data_source_id` | As above |
| `min` / `max` | How many must/may be selected |
| `default` | Pre-selected values |

Delivers an array of value strings.

### Date

A date, time, or date+time.

| Option | Effect |
| --- | --- |
| `format` | `date`, `time`, or `datetime-local` — controls the picker and the stored format |
| `min` / `max` | Earliest / latest allowed |
| `use_current_as_default` | New entries default to "now" |
| `translatable` | Per-language values (e.g. market-specific publication dates) |

---

## Media & reference fields

### Asset

One file from the [asset library](assets.md).

| Option | Effect |
| --- | --- |
| `file_types` | Restrict to `image`, `video`, `audio`, `document`, `archive`, `other`, or `all` |
| `folder_id` | Start the picker in a specific folder |

Delivers the asset object — `id`, `full_path` (feed it to the [image service](image-service.md)), filename, alt text, dimensions, and metadata.

### Multi assets

An **ordered** list of assets — galleries, downloads, slideshows.

| Option | Effect |
| --- | --- |
| `file_types` | As above |
| `min` / `max` | How many files must/may be attached |

### References

Pointers to other **content entries** — related articles, authors, products.

| Option | Effect |
| --- | --- |
| `block_whitelist` | Only allow entries of specific content types |
| `min` / `max` | How many entries must/may be referenced |

Delivers the referenced entries' data, so one API request is still enough to render the page.

### Link

A flexible link that editors can point at a page, an external URL, or an email address:

```json
{ "type": "internal", "content": "01JW…", "anchor": "pricing", "target": "_self" }
{ "type": "url",      "url": "https://…", "target": "_blank", "rel": "…" }
{ "type": "email",    "email": "…", "subject": "…", "cc": "…" }
```

| Option | Effect |
| --- | --- |
| `asset_link_type` | Allow linking to assets |
| `email_link_type` | Allow email links |
| `allow_target_blank` | Offer "open in new tab" |
| `allow_query_params` | Let editors add query parameters |
| `translatable` | Per-language link targets |

Internal links store the target entry's **ID**, not its URL — they keep working when slugs change. Resolve them in your frontend (the SDKs' link helpers do this).

### Icon

An icon from the space's own [icon registry](icons.md) — and, if you allow it, from the public Iconify universe of 200,000+ open-source icons.

| Option | Effect |
| --- | --- |
| `source` | `registry` — only your uploaded icons; `all` — registry + every public Iconify collection; `collections` — registry + an allowlist |
| `allowed_collections` | The allowlisted Iconify collections (when `source: collections`) |

---

## Structured fields

### Blocks

The composition workhorse: an **ordered list of nested block instances**. This is what makes b10cks block-based — a page's `body` field can hold heroes, text sections, galleries, CTAs, in any order editors choose.

| Option | Effect |
| --- | --- |
| `restrict_blocks` + `block_whitelist` | Only allow specific blocks |
| `restrict_tags` + `tag_whitelist` | Allow any block carrying one of these tags — tag new blocks and they become available everywhere the tag is allowed, no schema edits needed |

Delivers an array of nested block objects, each with its own `block` name — rendering is one recursive component ([guide](../guides/nuxt.md#2-map-blocks-to-components)).

### Meta

A ready-made SEO metadata group so you don't have to model it yourself: `title`, `description`, robots directives — plus Open Graph fields (`ogTitle`, `ogDescription`, `ogImage`) when enabled.

| Option | Effect |
| --- | --- |
| `has_og_tags` | Adds the Open Graph fields |
| `translatable` | Per-language metadata |

Map it to your framework's SEO utilities once ([example](../guides/nuxt.md#6-seo-metadata)) and editors control search and social previews per page.

### Table

Tabular data with **typed columns** — comparison tables, spec sheets, pricing grids.

| Option | Effect |
| --- | --- |
| `columns` | Each column is `text`, `number`, `boolean`, or `option` (options again from the field or a data source) |
| `has_thead` | Include a header row |
| `min` / `max` | Row count bounds |
| `translatable` | Per-language tables |

Delivers `{ header, rows: [{ id, cells }] }`.

### Geo

A geographic coordinate with a map picker.

| Option | Effect |
| --- | --- |
| `key_style` | The JSON keys the value is stored under: `{latitude, longitude, altitude}`, `{lat, lng, alt}`, or `{lat, lon, alt}` — match the shape your map library expects, no remapping needed |
| `altitude` | Collect altitude too |
| `map` | Show the map picker (vs. plain inputs) |

### Price

A structured monetary value for multi-currency, multi-market pricing.

| Option | Effect |
| --- | --- |
| `base_currency` | The primary currency |
| `currencies` | Additional currencies — including regional codes like `US-USD` or `AT-EUR`, so the same currency can carry different prices per market |

---

## What your frontend receives

- Field values appear in the Data API under the entry's `content` object, keyed by field name.
- Empty fields may be omitted — code defensively (`block.headline ?? ''`).
- `translatable` fields arrive already resolved for the requested `language`, with fallback to the canonical value — your frontend never merges languages itself ([i18n](internationalization.md)).
