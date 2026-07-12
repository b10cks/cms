---
description: "Connect AI assistants to b10cks via MCP: setup for Claude, Cursor, and Windsurf, operations, examples, and safety notes."
---

# MCP Server (AI Assistants)

[`@b10cks/mcp-server`](https://www.npmjs.com/package/@b10cks/mcp-server) exposes the full [Management API](../api/management-api.md) to AI assistants via the [Model Context Protocol](https://modelcontextprotocol.io) — Claude Code, Claude Desktop, Cursor, Windsurf, or anything else that speaks MCP. Connected, an assistant can model blocks, scaffold content trees, manage redirects, translate data sources, and run releases — everything the admin UI can do, in natural language:

> *"Create a `testimonial` block with a quote, author, and avatar field, then add three example testimonials under /about."*

## Prerequisites

- Node.js ≥ 20
- A **personal access token** — create one in your account security settings. The server acts with your permissions.

## Setup

The server runs locally as a stdio process; MCP clients launch it themselves. Install globally (`bun add -g @b10cks/mcp-server`) or let `npx -y @b10cks/mcp-server` fetch it on demand.

Configuration is two values, passed as CLI flags or environment variables (flags win):

| Flag | Env var | Value |
| --- | --- | --- |
| `--base-url` | `B10CKS_MGMT_BASE_URL` | API root, e.g. `https://api.b10cks.com` (or your self-hosted instance) |
| `--token` | `B10CKS_MGMT_TOKEN` | Your personal access token |
| `--timeout` | `B10CKS_MGMT_TIMEOUT` | Request timeout in ms (default `30000`) |

The server health-checks the API on startup and exits with a clear error on a bad token or unreachable instance — no silent failures on the first tool call.

### Claude Code

```bash
claude mcp add b10cks -- npx -y @b10cks/mcp-server \
  --base-url https://api.b10cks.com --token pat_xxxxxxxxxxxx
```

### Claude Desktop

`~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "b10cks": {
      "command": "npx",
      "args": ["-y", "@b10cks/mcp-server"],
      "env": {
        "B10CKS_MGMT_BASE_URL": "https://api.b10cks.com",
        "B10CKS_MGMT_TOKEN": "pat_xxxxxxxxxxxx"
      }
    }
  }
}
```

Restart Claude Desktop after editing.

### Cursor / Windsurf

Same JSON shape in `.cursor/mcp.json` (project or global) or `~/.codeium/windsurf/mcp_config.json`.

### Programmatic use

For custom transports (e.g. SSE in a web app), the package exports the building blocks:

```ts
import { createServer, createManagementClient } from '@b10cks/mcp-server'

const client = createManagementClient({
  baseUrl: 'https://api.b10cks.com',
  token: process.env.B10CKS_MGMT_TOKEN!,
})
const server = createServer(client)
// connect server to your transport
```

## How it works

The server exposes two tools:

| Tool | Purpose |
| --- | --- |
| `b10cks_mgmt_operations` | Lists all operations with descriptions and required/optional arguments |
| `b10cks_mgmt_call` | Executes any operation |

Operations follow a `resource.method` convention and take a small, consistent argument set: `operation`, `spaceId` (almost everything is space-scoped), a resource ID where needed (`contentId`, `blockId`, `releaseId`, …), `params` for queries, and `payload` for bodies.

```jsonc
{ "operation": "contents.list", "spaceId": "<space-id>", "params": { "per_page": 25 } }
```

**177 operations across 19 resource groups**: system, users, teams, spaces (incl. members, AI settings, backups, migrations, audit logs), blocks (incl. templates, versions, folders, tags), contents (incl. tree operations, publishing, scheduling, versions), comments, redirects, data sources, automations, releases, assets, tokens, AI (translation, meta tags), and provider. If it's in the [Management API](../api/management-api.md), it's callable.

## Worked examples

Things to ask an assistant, and roughly what it calls:

**Model a schema** — *"Add a `theme` option (light/dark) to the hero block"*

```jsonc
{
  "operation": "blocks.update",
  "spaceId": "<space-id>",
  "blockId": "<block-id>",
  "payload": { "schema": { /* existing fields… */ "theme": { "type": "option", "options": ["light", "dark"] } } }
}
```

**Scaffold a site structure** — *"Create a blog folder with three example posts"*

```jsonc
{
  "operation": "contents.bulkCreate",
  "spaceId": "<space-id>",
  "payload": {
    "items": [
      { "name": "Blog", "slug": "/blog", "component": "folder" },
      { "name": "Post 1", "slug": "/blog/post-1", "component": "blog_post" }
    ]
  }
}
```

**Run a release** — *"Publish everything in the Q3 launch release"*

```jsonc
{ "operation": "releases.publish", "spaceId": "<space-id>", "releaseId": "<release-id>" }
```

**Translate a data source** — *"Fill in the missing French and Spanish labels"*

```jsonc
{
  "operation": "dataSources.translateMissingDimensions",
  "spaceId": "<space-id>",
  "dataSourceId": "<data-source-id>",
  "payload": { "target_locales": ["fr", "es"] }
}
```

## Safety notes

- The token acts as **you** — every change is attributed and [audit-logged](../ui/audit-logs.md) like any other Management API call.
- Content changes land as **drafts** unless the assistant explicitly publishes; block versioning and content history give you restore points either way.
- Use a dedicated token so assistant access can be revoked independently.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Server exits immediately on startup | Wrong `--base-url`, or the token is invalid / a Data API token instead of a personal access token. |
| `Missing Management API base URL` / `token` | Neither the flag nor the env var was provided. |
| `Missing required string argument: spaceId` | Most operations are space-scoped — call `spaces.list` first to find the ID. |
| Operation returns 403 | Your account (or the token) lacks permission in that space — the same roles as the admin UI apply. |
| `npx` runs a stale version | Force the latest: `npx -y @b10cks/mcp-server@latest`. |
