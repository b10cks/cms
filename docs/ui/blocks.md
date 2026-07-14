---
description: 'Managing the block library: creating blocks, editing schemas, folders, tags, and templates.'
---

# Block Library

The **Blocks** section is where you define what your content is made of. A block is a reusable content type — "Page", "Hero", "Testimonial" — with a set of fields that editors fill in. Design the blocks once, and your whole team builds pages from them like Lego. Concepts behind all of this: [Blocks](../concepts/blocks.md) and [Fields](../concepts/fields.md).

## The library

Blocks are listed with their icon, color, name, type, tags, and folder — searchable and filterable by all of them.

- **Folders** keep a growing library navigable (deleting a folder moves its blocks to the root — nothing is lost).
- **Tags** group blocks across folders — and do double duty: a _Blocks_ field can allow "anything tagged `content-section`", so tagging a new block instantly makes it available everywhere that tag is welcome, without touching any schema.
- **Icon and color** give each block a recognizable identity in the editor, the content tree, and the block picker.

## Creating a block

Click **Create Block** and set:

|                        |                                                                                                                                                                                                                                                                                                                         |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Name & description** | What editors see when picking a block. The technical name (slug) is derived from the name — developers match components to it.                                                                                                                                                                                          |
| **Type**               | Where the block can be used: **Root** (a page of its own — blog post, landing page), **Nestable** (a building brick inside other blocks — hero, gallery, CTA), **Singleton** (exactly one instance ever — navigation, footer), or **Universal** (both a page and a brick). [Details](../concepts/blocks.md#block-types) |
| **Icon & color**       | Its visual identity                                                                                                                                                                                                                                                                                                     |
| **Preview image**      | A screenshot shown in the block picker, so editors recognize blocks by sight, not name                                                                                                                                                                                                                                  |
| **Folder & tags**      | Where it lives and how it groups                                                                                                                                                                                                                                                                                        |

Then build its fields.

## The schema editor

A block's schema is its list of fields. In the schema editor you can:

- **Add fields** — pick one of the 19 types ([full reference with every option](../concepts/fields.md)), from single-line text to nested block lists.
- **Reorder** by drag-and-drop — the editing form follows this order.
- **Copy & paste fields between blocks** — the field clipboard carries the complete configuration, so a carefully tuned rich text setup moves to another block in two clicks.
- **Rename a field key** — with honest guardrails: the dialog warns that existing content keeps its data under the old key and frontends reading it will break, while conditions inside the block that reference the field are updated automatically. Rename labels freely; rename _keys_ deliberately.

## Field settings

Every field has the shared settings — required, translatable (gets per-language values), indexable (found by search), default value (pre-filled when editors create content), validation (lengths, counts, patterns) — plus its type-specific options. Three highlights:

- **Conditions** — show a field only when other fields match rules ("show `videoUrl` only when `layout` is `video`"), with all/any logic. Forms stay short; complexity appears only when relevant.
- **Options from data sources** — dropdown fields can take their choices from a central [data source](data-sources.md) instead of a hardcoded list, so "Departments" is maintained once, used everywhere.
- **Rich text configuration** — decide exactly what editors can format: which heading levels, which features (bold, tables, code…; disabled features are removed _entirely_, and pasted Word content is cleaned to match), named text styles and list styles that map to your CSS classes, and placeholder tokens like `{firstName}` that the website fills in at display time.

## Editor layout

Group fields into named **editor pages** — sections/tabs of the editing form like _Content_, _SEO_, _Settings_. Pure presentation: the API output doesn't change, but a 25-field block becomes a pleasant form instead of a wall.

## The Usage panel

Before refactoring or deleting a block, the **Usage** panel answers "where does this block appear?":

- **Can be nested inside** — which other blocks allow it in their block lists
- **Can be referenced by** — which blocks point to it via reference fields

No detective work, no surprises in production.

## Templates

A **template** is a saved, pre-filled block — "Testimonial, two-column, with photo" — that editors insert instead of an empty block. Create one from any existing block instance (_Save as template_), give it a name, description, and preview image. Templates keep frequently-built patterns consistent and save everyone the setup clicks.

## Version history

Block definitions get the same git-like history as content: every save is a versioned snapshot with author and an optional **commit message** ("split `body` into intro + content"). The versions panel groups history by time and offers, per version:

- a **Changes** tab — what exactly differs from the previous version
- a **Preview** tab — the definition as it was
- **Restore** — roll the schema back without reconstructing it by hand

Schema refactors stop being scary: change boldly, compare honestly, roll back instantly.

> **Good to know:** removing or renaming a field never rewrites existing content — stored values remain under their old key (editors see them as _Out of schema_ in affected entries). Add fields freely; rename keys deliberately.
