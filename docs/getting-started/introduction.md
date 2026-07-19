---
description: "What b10cks is and how it works: block-based content modeling, a visual editor, and a fast cached Data API — SaaS or self-hosted."
---

# Introduction

b10cks is an API-first, block-based headless CMS. Content is modeled as reusable **blocks**, edited in a visual admin UI, and delivered to any frontend over a fast, cached REST API with official SDKs for Nuxt, Vue, React, Next.js, Svelte, and plain JavaScript.

## How the pieces fit together

```
┌────────────────┐     Management API      ┌──────────────────┐
│   Admin UI     │ ──────────────────────▶ │                  │
│  (editors,     │                         │   b10cks CMS     │
│   developers)  │ ◀── real-time events ── │  (your instance  │
└────────────────┘                         │   or b10cks      │
                                           │   Cloud)         │
┌────────────────┐      Data API           │                  │
│ Your frontend  │ ◀────────────────────── │                  │
│ (Nuxt, Next,…) │      Image service      │                  │
└────────────────┘ ◀────────────────────── └──────────────────┘
```

- **Spaces** are isolated content environments — each with its own database, content, assets, team, and settings. A typical setup is one space per website or app. See [Spaces](../concepts/spaces.md).
- **Blocks** are your content types. A block defines a schema of typed fields — from plain text to rich text, assets, references, and nested block lists. See [Blocks](../concepts/blocks.md) and [Fields](../concepts/fields.md).
- **Content** entries are trees of blocks, organized in a hierarchical tree that usually mirrors your site's URL structure. Every entry is versioned: editors work on drafts and publish explicitly, optionally scheduled or grouped into [Releases](../concepts/releases.md). See [Content](../concepts/content.md) and [Versions & publishing](../concepts/versions-and-publishing.md).
- The **Data API** delivers published (or draft) content as JSON, filtered, sorted, localized, and heavily cached. See [Querying content](../guides/querying-content.md).
- The **visual editor** loads your real frontend in an iframe: editors click blocks to select them, edit inline, and see changes live. See [Live preview & visual editing](../guides/live-preview.md).
- The **image service (Ilum)** transforms assets on the fly — resize, crop, smart gravity, WebP/AVIF — via URL operations. See [Image service](../concepts/image-service.md).

## The editing experience

The admin UI is a Vue application with:

- a **content tree** with drag-and-drop organization, filtering, and bulk operations
- a **form editor** with live preview, and the **Canvas** — an infinite whiteboard for planning and restructuring whole site sections
- **git-like version history** — every save is a commit with author and message; schema-aware diffs; nothing is ever overwritten
- **real-time collaboration** — presence, live co-editing, and threaded comments
- an **asset manager** with folders, tags, collections, and public shares
- **AI assistance** for content generation, translation, and metadata
- releases, redirects, data sources, icons, automations, audit logs, backups, and per-space settings

The [user guide](../ui/dashboard.md) covers every screen.

## Hosted or self-hosted

- **b10cks Cloud** — managed hosting at [app.b10cks.com](https://app.b10cks.com); you only build the frontend.
- **Self-hosted** — the CMS is open source (AGPL-3.0), built on Laravel + Vue, and runs anywhere PHP does. See the [Self-hosting section](../self-hosting/index.md).

Both expose the same APIs, so SDK code is identical either way — only the `apiUrl` differs.

## Where to go next

1. [Quickstart](quickstart.md) — model a page, publish it, and fetch it via the API.
2. [Nuxt guide](../guides/nuxt.md) — build a production frontend (or start from the [boilerplate](https://github.com/b10cks/nuxt-boilerplate)).
3. [Concepts](../concepts/spaces.md) — deepen your mental model.
