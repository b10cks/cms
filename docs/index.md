---
description: "Official documentation for b10cks — model content as reusable blocks, edit it visually, deliver it anywhere through a fast, cached Data API."
layout: home

hero:
  name: b10cks
  text: The opinionated headless CMS
  tagline: Model content as reusable blocks, edit it visually, deliver it anywhere through a fast, cached Data API.
  image:
    src: /b10cks.png
    alt: b10cks
  actions:
    - theme: brand
      text: Get Started
      link: /getting-started/introduction
    - theme: alt
      text: Quickstart
      link: /getting-started/quickstart
    - theme: alt
      text: API Reference
      link: /api/overview

features:
  - title: Git-like content history
    details: Every save is a commit with author and message; history branches instead of overwriting, and schema-aware diffs make review effortless.
    link: /concepts/versions-and-publishing
  - title: Live collaboration
    details: Figma-style presence and real-time co-editing, down to individual blocks — comments included.
    link: /concepts/content
  - title: The Canvas
    details: Plan and restructure whole site sections on an infinite whiteboard, then apply the plan in one click.
    link: /ui/canvas
  - title: Your own Iconify registry
    details: Brand icons served through the Iconify protocol, straight into the tooling developers already use.
    link: /concepts/icons
  - title: A real query API
    details: 16 filter operators, sorting by your own content fields, language fallback, and revision-based caching — all in plain GET requests.
    link: /guides/querying-content
  - title: Settings where they belong
    details: Per-entry child sorting and nesting rules, per-folder asset metadata requirements, per-field editor configuration. Structure without bureaucracy.
    link: /concepts/content
---

## Start here

| You are… | Read |
| --- | --- |
| **New to b10cks** | [Introduction](/getting-started/introduction), then the [Quickstart](/getting-started/quickstart) — empty space to rendered page in ~10 minutes |
| **An editor or content team member** | The [user guide](/ui/dashboard) — friendly walkthroughs of every screen |
| **A developer building a frontend** | The [Nuxt guide](/guides/nuxt) (or your framework's), plus [Querying content](/guides/querying-content) |
| **Running your own instance** | [Self-hosting](/self-hosting/) — installation, configuration, plans & pricing |

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
| [`@b10cks/mgmt-client`](https://github.com/b10cks/sdk/tree/main/packages/mgmt-client) | Typed Management API client — [guide](/guides/management-client) |
| [`@b10cks/cli`](https://github.com/b10cks/sdk/tree/main/packages/cli) | Terminal workflows & TypeScript type generation — [guide](/guides/cli) |
| [`@b10cks/mcp-server`](https://github.com/b10cks/sdk/tree/main/packages/mcp-server) | MCP server for AI assistants — [guide](/guides/mcp-server) |
