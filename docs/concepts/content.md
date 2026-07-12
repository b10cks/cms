---
description: "The content tree, entry anatomy, per-entry settings, live collaboration, and delivery."
---

# Content

A **content entry** is an instance of a root (or universal/singleton) block, placed in the space's hierarchical content tree. Entries are what editors create, what gets published, and what the Data API delivers.

## Anatomy of an entry

| Property | Description |
| --- | --- |
| `name` | Display name in the admin UI (also the default page title) |
| `slug` | URL segment of this entry |
| `full_slug` | Slug path from the root, e.g. `blog/2026/hello-world` — this is the key you fetch by |
| `block` | The root block the entry is based on (its slug in delivery payloads) |
| `content` | The field values, including nested block instances |
| `parent_id` / `position` | Place in the tree and order among siblings |
| `language_iso` / `i18n_parent_id` | Translation linkage — see [Internationalization](internationalization.md) |
| `settings` | Per-entry configuration (child sorting, nesting restrictions, …) |
| `published_at` / `first_published_at` | Publication timestamps |

## The content tree

Entries form a tree that usually mirrors your URL structure: folders are just entries with children (any entry can have children, subject to nesting rules). The tree drives:

- **`full_slug`** — built from the parents' slugs; moving an entry updates its path
- **Navigation** — menus and sitemaps derive from the published tree
- **Queries** — `parent_id` / `canonical_parent_id` filters fetch children of a node ([Querying content](../guides/querying-content.md))

## Per-entry settings

Every entry carries its own settings, so a folder can behave differently from the rest of the tree — no global switches, no code:

| Setting | What it controls |
| --- | --- |
| **Child sorting** | How this entry's children are ordered: inherit the space default, manual (drag-and-drop `position`), a system field (`name`, `published_at`, `created_at`, `updated_at`), or **any first-level content field** via `content.{field}` — e.g. sort event pages by their own `startDate`. Ascending or descending. The configured order becomes the default order the Data API returns those children in. |
| **Child restrictions** | Which content types may be created beneath this entry — a whitelist of blocks and/or block tags, plus a **default child type** so "New sub item" starts with the right block. Keeps trees tidy: only `article` entries under the blog, only `product` entries under the catalogue. |
| **Language mode override** | Override the space's i18n mode (overlay vs. independent) for this subtree ([i18n](internationalization.md)). |
| **Caching** | A per-entry cache TTL and **cache tags** delivered with the entry via the Data API — automations can forward the tags to your CDN or app for targeted invalidation when the entry changes ([Automations](../ui/automations.md)). |
| **Preview** | Disable the live preview panel for entries that have no page of their own (e.g. pure data containers). |

## Drafts, versions, publishing

Editing never mutates the published state directly. Every entry has a **current version** (the draft editors work on) and optionally a **published version** (what the Data API serves by default). Publishing, scheduling, selective publishing, and version history are covered in [Versions & publishing](versions-and-publishing.md).

## Live collaboration

Content editing in b10cks is real-time, Figma-style — no locking, no "someone else is editing this" walls:

- **Presence** — avatars show who has the entry open, down to which part they're working in.
- **Live changes** — edits propagate to everyone instantly, at block granularity, so two people can work on different sections of the same page without stepping on each other.
- **Late joiners catch up** — opening an entry someone else has been editing shows their unsaved work too, and saving surfaces whose changes you're about to include.
- **Comments** — threaded discussions attach directly to entries, with mentions (which notify), emoji reactions, and resolve/unresolve tracking.
- It even extends to the **translation editor**, where translators collaborate live alongside content editors.

See the [content editor user guide](../ui/content.md).

## Delivery

The Data API serves entries by full slug (`GET /api/v1/contents/{full_slug}`) or as filtered collections (`GET /api/v1/contents`). Payloads embed the whole nested block tree in one response — one request renders one page. Delivery is cached and revision-stamped; see [Access tokens & caching](access-tokens.md).
