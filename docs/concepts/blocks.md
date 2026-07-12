---
description: "Blocks are b10cks' content types: schemas, block types, nesting rules, templates, and versioning."
---

# Blocks

Blocks are the content types of b10cks. A block defines a **schema** — a set of typed fields — and content entries are trees of block instances. Model once, compose everywhere: a `page` block with a nested `blocks` field can contain heroes, galleries, CTAs, and any other building blocks you define.

## Block types

Every block has one of four types, which controls where it can be used:

| Type | Use for | Behavior |
| --- | --- | --- |
| **Root** | Blog posts, product pages, landing pages | Created directly in the content tree as content entries. |
| **Nestable** | Hero sections, galleries, CTAs | Only usable inside other blocks' **Blocks** fields. |
| **Singleton** | Navigation, footer, site settings | Exactly one content entry allowed; cannot nest blocks. |
| **Universal** | Flexible components | Both: creatable in the content tree *and* nestable inside other blocks. |

## Schema

The schema is an ordered map of field name → field definition. Each field has a type (Text, Rich text, Asset, Blocks, References, …) plus common settings:

- **required**, **default value** (applied automatically when editors create content or insert the block), **min/max**
- **translatable** — the field gets per-language values; see [Internationalization](internationalization.md)
- **indexable** — the field participates in full-text search
- **conditions** — show the field only when other fields match rules (all/any of a rule list with operators like `equals`, `in`, `is_empty`, `gt`, `contains`)
- **validation** — length/item counts, regex patterns, allowed values

The full catalogue of field types and their options is in [Fields](fields.md).

### Editor layout

Beyond the schema itself, a block defines **editor pages** — named groups of fields that structure the editing form into sections/tabs. Layout is purely presentational; it doesn't affect the API output.

### Nesting rules

A **Blocks** field can restrict which blocks it accepts, by explicit whitelist and/or by block **tags** (e.g. allow anything tagged `content-section`). Root/universal blocks can likewise restrict which child content types are allowed beneath a content entry.

## Organization

- **Folders** organize the block library.
- **Tags** group blocks across folders and double as nesting whitelist criteria.
- **Icon & color** give each block a recognizable identity in the editor and content tree.
- A **preview image** (from your asset library) helps editors pick the right block visually when inserting one.

## Templates

A **block template** is a saved, pre-filled block instance — e.g. a "Testimonial – two column" starting point. Editors insert templates instead of empty blocks and tweak from there. Templates can be created from any existing block instance in the editor.

## Versioning

Block definitions are versioned. Every save creates a version with the full definition (schema, editor layout, metadata), an optional **commit message**, and its author. You can inspect and restore previous versions — useful before refactoring a schema that live content depends on.

> **Schema changes and existing content.** Content stores field values by field name. Removing or renaming a field doesn't rewrite existing content; the value simply stops being part of the schema (and is dropped from delivery). Add fields freely; rename with care.

## Blocks in the Data API

Block definitions are readable through the Data API (`GET /api/v1/blocks`), which frontends can use for dynamic form generation, type generation, or validation. The block a content entry is based on appears in delivery payloads as its technical slug (`"block": "page"`), which SDK component resolvers map to your UI components.

## Related

- [Fields](fields.md) — every field type in detail
- [Content](content.md) — how block instances become content
- [Block library (user guide)](../ui/blocks.md) — the editing UI
