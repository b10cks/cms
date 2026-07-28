---
description: "Connect AI assistants to b10cks via MCP: the built-in HTTP endpoint, local stdio setup for Claude, Cursor, and Windsurf, all tools and operations, examples, and safety notes."
---

# MCP Server (AI Assistants)

b10cks exposes the full [Management API](../api/management-api.md) to AI assistants via the [Model Context Protocol](https://modelcontextprotocol.io) — Claude Code, Claude Desktop, Cursor, Windsurf, or anything else that speaks MCP. Connected, an assistant can model blocks, scaffold content trees, manage redirects, translate data sources, and run releases — everything the admin UI can do, in natural language:

> *"Create a `testimonial` block with a quote, author, and avatar field, then add three example testimonials under /about."*

There are two ways to connect:

| Transport | Best for | Requirements |
| --- | --- | --- |
| **Built-in HTTP endpoint** (`/mcp/v1`) | Most setups — nothing to install, always in sync with your instance | An MCP client that supports streamable HTTP |
| **Local stdio process** ([`@b10cks/mcp-server`](https://www.npmjs.com/package/@b10cks/mcp-server) via npm) | Clients without HTTP transport support, or air-gapped assistant setups | Node.js ≥ 20 |

Both expose the identical tools and operations. The HTTP endpoint is served by the CMS itself, so it can never drift from the API version you're running.

## Prerequisites

- A **personal access token** — create one in your account security settings (`users.createToken` works too). The server acts with your permissions.
- For the stdio variant: Node.js ≥ 20.

## Setup: built-in HTTP endpoint

The endpoint lives at `https://api.b10cks.com/mcp/v1` (or `https://<your-instance>/mcp/v1` when self-hosting) and authenticates with your token as a `Bearer` header — the same Sanctum authentication as the rest of the Management API.

### Claude Code

```bash
claude mcp add --transport http b10cks https://api.b10cks.com/mcp/v1 \
  --header "Authorization: Bearer pat_xxxxxxxxxxxx"
```

### Cursor

`.cursor/mcp.json` (project or global):

```json
{
  "mcpServers": {
    "b10cks": {
      "url": "https://api.b10cks.com/mcp/v1",
      "headers": { "Authorization": "Bearer pat_xxxxxxxxxxxx" }
    }
  }
}
```

### Other clients

Any client with streamable-HTTP support takes the same two values: the URL and the `Authorization` header. For clients that only speak stdio, either use the npm package below or bridge with [`mcp-remote`](https://www.npmjs.com/package/mcp-remote).

## Setup: local stdio process

The [`@b10cks/mcp-server`](https://www.npmjs.com/package/@b10cks/mcp-server) package runs locally as a stdio process; MCP clients launch it themselves. Install globally (`bun add -g @b10cks/mcp-server`) or let `npx -y @b10cks/mcp-server` fetch it on demand.

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

### Windsurf

Same JSON shape in `~/.codeium/windsurf/mcp_config.json`.

## Tools

The server exposes three tools and one resource:

| Tool | Purpose |
| --- | --- |
| `b10cks_mgmt_operations` | Lists all operations with descriptions and required/optional arguments — the assistant's index into the API |
| `b10cks_content_model_guide` | Returns the content modeling guide: block types, field types, tag hierarchy, editor layout, and canonical block examples |
| `b10cks_mgmt_call` | Executes any operation |

The guide is also published as the MCP resource `b10cks://content-model-guide`, so clients that surface resources can pin it into context. Assistants are instructed to read it before designing or creating blocks — it encodes the same rules the API validates (slug format, translatable/indexable constraints, editor-tab assignment) plus patterns from a production project, which turns "make me a hero block" from trial-and-error into a one-shot call.

## Calling operations

Operations follow a `resource.method` convention and take a small, consistent argument set:

- `operation` — the operation name, e.g. `contents.list` or `blocks.create`
- `spaceId` — almost everything is space-scoped
- a resource ID where needed — either the specific name (`contentId`, `blockId`, `releaseId`, …) or the generic `id`, which is accepted as a fallback everywhere a specific ID is expected
- `params` — query parameters for list/search operations (`page`, `per_page`, filters, sorts)
- `payload` — the JSON request body for create/update/action operations

```jsonc
{ "operation": "contents.list", "spaceId": "<space-id>", "params": { "per_page": 25 } }
```

Responses are the Management API's JSON, pretty-printed. Errors surface the API's status code, message, and validation errors — a `422` tells the assistant exactly which payload field to fix.

## Operation catalog

**283 operations** cover the entire Management API surface. If it's in the [Management API](../api/management-api.md), it's callable. By resource group:

| Group | Covers |
| --- | --- |
| `system.*` | Health, public config, plans, effective permissions, space blueprints, public invites |
| `users.*`, `notifications.*` | Profile, settings, password, personal access tokens, social links, invites, notifications |
| `teams.*` | Teams, members, invites, SAML providers, space blueprints, space roles |
| `spaces.*` | Spaces, members, invites, people directory, subscriptions (incl. resume, payment requests, available plans), AI settings/configs, audit logs, backups, migrations, search, usage & invoices, onboarding |
| `blocks.*`, `blockFolders.*`, `blockTags.*` | Block definitions, declarative [`blocks.sync`](../api/management-api.md), templates, restorable versions, folders, tags |
| `contents.*`, `comments.*` | Content CRUD, bulk create, tree operations, publish/unpublish/schedule, versions, export, comments & reactions |
| `assets.*`, `assetFolders.*`, `assetTags.*`, `assetCollections.*`, `assetShares.*`, `assetPackages.*`, `shares.*` | Assets, versions, linked contents, folders, tags, manual & smart collections, public shares (incl. password unlock), zip packages |
| `dataSources.*` | Data sources, entries, import/export, AI translation of missing dimensions |
| `fieldPlugins.*` | Sandboxed iframe custom field plugins — the `plugin` field type |
| `redirects.*` | Redirects, import/export, hit-counter reset |
| `automations.*` | Automations, actions, trigger catalog, manual triggers, executions & replay, stats |
| `releases.*` | Releases, version assignment, commit, publish, cancel |
| `tokens.*` | Space access tokens for the Data API |
| `ai.*` | Available models, translation, meta-tag generation, content interactions |
| `icons.*` | Space icon registry and tags |
| `provider.*` | Provider-level stats and notes (self-hosted/agency instances) |

Call `b10cks_mgmt_operations` for the authoritative list — it always reflects the connected instance.

Not exposed: endpoints that take file uploads (avatar images, asset file replacement, icon/asset/content imports) — operation calls carry JSON only — and the browser-only realtime presence endpoints.

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

**Sync a whole content model** — *"Make the space match these 12 block definitions"*

```jsonc
{
  "operation": "blocks.sync",
  "spaceId": "<space-id>",
  "payload": { "blocks": [ /* full definitions with external_id */ ], "dry_run": true }
}
```

`blocks.sync` is declarative: send the full set, get a created/updated/unchanged/deleted plan with `dry_run: true`, then apply. Every update creates a restorable block version.

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

## Self-hosting

The HTTP endpoint ships with the CMS — `POST /mcp/v1`, behind the same middleware stack as the private Management API (`auth:sanctum` + verified email). Nothing to enable.

Tool calls are dispatched **internally** to the corresponding Management API route: the same routing, authentication, authorization policies, request validation, and API resources run as for any HTTP client. There is no parallel code path to secure or keep in sync, and rate limiting (`mgmt` throttle) applies to MCP traffic like any other.

A stdio variant of the same server is available on the host itself:

```bash
B10CKS_MCP_TOKEN=pat_xxxxxxxxxxxx php artisan mcp:start b10cks
```

`B10CKS_MCP_TOKEN` is a personal access token used to authenticate the internal calls (config: `services.b10cks_mcp.token`). This is mainly useful for assistants running directly on the server and for debugging with `php artisan mcp:inspector`. For everything else, prefer the HTTP endpoint.

## Safety notes

- The token acts as **you** — every change is attributed and [audit-logged](../ui/audit-logs.md) like any other Management API call, and your space roles apply unchanged.
- Content changes land as **drafts** unless the assistant explicitly publishes; block versioning and content history give you restore points either way.
- Use a **dedicated token** so assistant access can be revoked independently of your other sessions.
- Destructive operations (`*.delete`, `blocks.sync` with `prune: true`) are plain API calls — pair the assistant with a client that asks before writing, and prefer `dry_run` where an operation offers it.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| HTTP endpoint returns 401 | Missing/invalid `Authorization` header, or the token is a Data API token instead of a personal access token. |
| HTTP endpoint returns 403 | The account's email address isn't verified yet. |
| stdio server exits immediately on startup | Wrong `--base-url`, or the token is invalid. |
| `Missing Management API base URL` / `token` | Neither the flag nor the env var was provided (stdio only). |
| `Missing required string argument: spaceId` | Most operations are space-scoped — call `spaces.list` first to find the ID. |
| Operation returns 403 | Your account (or the token) lacks permission in that space — the same roles as the admin UI apply. |
| Operation returns 422 | The payload failed validation — the error lists the offending fields; for blocks, check `b10cks_content_model_guide`. |
| `npx` runs a stale version | Force the latest: `npx -y @b10cks/mcp-server@latest`. |
