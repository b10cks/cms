# b10cks CMS Documentation

b10cks is an API-first headless CMS. You model content as reusable **blocks**, edit it in a visual admin UI, and deliver it to any frontend through a fast, cached **Data API** — with official SDKs for Nuxt, Vue, React, Next.js, Svelte, and plain JavaScript.

What makes it different:

- **Git-like content history** — every save is a commit with author and message; history branches instead of overwriting, and schema-aware diffs make review effortless. [→ Versions & publishing](concepts/versions-and-publishing.md)
- **Live collaboration** — Figma-style presence and real-time co-editing, down to individual blocks, comments included. [→ Content](concepts/content.md#live-collaboration)
- **The Canvas** — plan and restructure whole site sections on an infinite whiteboard, then apply the plan in one click. [→ Canvas](ui/canvas.md)
- **Your own Iconify registry** — brand icons served through the Iconify protocol, straight into the tooling developers already use. [→ Icons](concepts/icons.md)
- **A real query API** — 16 filter operators, sorting by your own content fields, language fallback, and revision-based caching, all in plain GET requests. [→ Querying content](guides/querying-content.md)
- **Settings where they belong** — per-entry child sorting and nesting rules, per-folder asset metadata requirements, per-field editor configuration. Structure without bureaucracy.

## Start here

| You are… | Read |
| --- | --- |
| **New to b10cks** | [Introduction](getting-started/introduction.md), then the [Quickstart](getting-started/quickstart.md) — empty space to rendered page in ~10 minutes |
| **An editor or content team member** | The [user guide](#using-the-app-user-guide) — friendly walkthroughs of every screen |
| **A developer building a frontend** | The [Nuxt guide](guides/nuxt.md) (or your framework's), plus [Querying content](guides/querying-content.md) |
| **Running your own instance** | [Self-hosting](self-hosting/index.md) |

## Concepts

Understand the building blocks of b10cks:

- [Spaces](concepts/spaces.md) — isolated content environments with their own database, team, and settings
- [Blocks](concepts/blocks.md) — content types: schema, block types, folders, tags, templates, and versioning
- [Fields](concepts/fields.md) — all 19 field types, validation, and conditional fields
- [Content](concepts/content.md) — the content tree, slugs, and the editing model
- [Versions & publishing](concepts/versions-and-publishing.md) — drafts, version history, scheduled and selective publishing
- [Releases](concepts/releases.md) — grouping versions and publishing them atomically
- [Internationalization](concepts/internationalization.md) — languages, translatable fields, fallbacks, and i18n content trees
- [Assets](concepts/assets.md) — the asset library: folders, tags, collections, packages, and public shares
- [Image service (Ilum)](concepts/image-service.md) — on-the-fly image transformation URLs
- [Data sources](concepts/data-sources.md) — key/value datasets with dimensions
- [Redirects](concepts/redirects.md) — managed URL redirects with hit tracking
- [Icons](concepts/icons.md) — per-space icon registry with an Iconify-compatible API
- [Automations & webhooks](concepts/automations.md) — triggers, templated payloads, secrets, and delivery semantics
- [Access tokens & caching](concepts/access-tokens.md) — Data API auth, revisions, and cache behavior

## Guides

Build a frontend on top of b10cks:

- [Nuxt](guides/nuxt.md) — the recommended full-featured setup (module, live preview, images, SEO, redirects)
- [Vue](guides/vue.md) — plain Vue 3 apps with `@b10cks/vue`
- [React](guides/react.md) — hooks and provider from `@b10cks/react`
- [Next.js](guides/nextjs.md) — server helpers and provider from `@b10cks/next`
- [Svelte](guides/svelte.md) — context, stores, and actions from `@b10cks/svelte`
- [JavaScript](guides/javascript.md) — the framework-agnostic `@b10cks/client`
- [Rendering rich text](guides/rich-text.md) — `@b10cks/richtext`, custom link and placeholder handling
- [Live preview & visual editing](guides/live-preview.md) — how the preview bridge works, and wiring it into your app
- [Querying content](guides/querying-content.md) — filters, sorting, pagination, search, and the sitemap endpoint

Tooling around the CMS:

- [CLI](guides/cli.md) — terminal workflows and TypeScript type generation from block schemas
- [Management client](guides/management-client.md) — the typed `@b10cks/mgmt-client` for scripting the Management API
- [MCP server](guides/mcp-server.md) — let AI assistants (Claude, Cursor, …) manage your spaces

## Using the app (user guide)

Documentation for every part of the admin UI:

- [Dashboard & navigation](ui/dashboard.md)
- [Content](ui/content.md) — content tree, editor, and content settings
- [Visual editor (Canvas)](ui/canvas.md)
- [Block library](ui/blocks.md)
- [Asset manager](ui/assets.md)
- [Data sources](ui/data-sources.md)
- [Icons](ui/icons.md)
- [Redirects](ui/redirects.md)
- [Releases](ui/releases.md)
- [Automations](ui/automations.md)
- [Audit logs](ui/audit-logs.md)
- [Space settings](ui/settings.md) — access tokens, people & roles, configuration, AI, backups, migrations, subscription, usage
- [Subscription & billing](ui/subscription.md) — plans, quotas, usage, invoices, and the subscription lifecycle
- [Agency billing](ui/agency-billing.md) — build a space for a client, let the client pay via a payment request
- [Account, teams & spaces](ui/account.md)

## Self-hosting

Run and operate your own instance:

- [Overview](self-hosting/index.md) — what's involved, the moving parts, single-team vs. platform
- [Installation](self-hosting/installation.md) — requirements, install steps, processes, upgrades
- [Configuration](self-hosting/configuration.md) — the `.env` reference
- [Plans & pricing](self-hosting/plans-and-pricing.md) — LemonSqueezy, the plan lineup, custom plans, quota overrides

## API reference

- [API overview](api/overview.md) — the three APIs, base URLs, authentication
- [Data API](api/data-api.md) — the public content delivery API
- [Management API](api/management-api.md) — the protected admin API
- [Generated OpenAPI specs](api/openapi.md) — how to generate and browse the machine-readable reference

## SDK packages

| Package | Purpose |
| --- | --- |
| [`@b10cks/client`](https://github.com/b10cks/sdk/tree/main/packages/client) | Framework-agnostic Data API client |
| [`@b10cks/richtext`](https://github.com/b10cks/sdk/tree/main/packages/richtext) | Zero-dependency rich text rendering (HTML / plain text) |
| [`@b10cks/vue`](https://github.com/b10cks/sdk/tree/main/packages/vue) | Vue 3 plugin, composables, editable directives |
| [`@b10cks/nuxt`](https://github.com/b10cks/sdk/tree/main/packages/nuxt) | Nuxt 4 module on top of `@b10cks/vue` |
| [`@b10cks/react`](https://github.com/b10cks/sdk/tree/main/packages/react) | React provider and hooks |
| [`@b10cks/next`](https://github.com/b10cks/sdk/tree/main/packages/next) | Next.js integration on top of `@b10cks/react` |
| [`@b10cks/svelte`](https://github.com/b10cks/sdk/tree/main/packages/svelte) | Svelte context, stores, and actions |
| [`@b10cks/mgmt-client`](https://github.com/b10cks/sdk/tree/main/packages/mgmt-client) | Typed Management API client — [guide](guides/management-client.md) |
| [`@b10cks/cli`](https://github.com/b10cks/sdk/tree/main/packages/cli) | Terminal workflows & type generation — [guide](guides/cli.md) |
| [`@b10cks/mcp-server`](https://github.com/b10cks/sdk/tree/main/packages/mcp-server) | MCP server for AI assistants — [guide](guides/mcp-server.md) |
