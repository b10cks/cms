---
description: "The protected admin API: structure, authentication, key workflow endpoints, and typed clients."
---

# Management API

The protected admin API — everything the admin UI does goes through it, so anything you can click, you can script. Base path: `/mgmt/v1`.

Three ways to consume it, all built on the same surface: raw REST (below), the typed [`@b10cks/mgmt-client`](../guides/management-client.md) for TypeScript, the [CLI](../guides/cli.md) for the terminal — and the [MCP server](../guides/mcp-server.md) hands it to AI assistants.

## Authentication

Create a **personal access token** in the admin UI (Account settings) and send it as a Bearer header:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://api.b10cks.com/mgmt/v1/spaces
```

Requests act with *your* permissions — space roles and team roles apply exactly as in the UI.

## Structure

Most resources are space-scoped:

```
/mgmt/v1/spaces                          # your spaces
/mgmt/v1/spaces/{space}/contents         # content entries
/mgmt/v1/spaces/{space}/blocks           # block definitions
/mgmt/v1/spaces/{space}/assets           # assets
/mgmt/v1/spaces/{space}/data-sources     # data sources (+ /entries)
/mgmt/v1/spaces/{space}/redirects        # redirects
/mgmt/v1/spaces/{space}/releases         # releases
/mgmt/v1/spaces/{space}/automations      # automations (+ actions, executions)
/mgmt/v1/spaces/{space}/backups          # backups
/mgmt/v1/spaces/{space}/migrations       # space migrations
/mgmt/v1/spaces/{space}/…                # settings, tokens, members, invites, audit-logs, usage
/mgmt/v1/teams …                         # teams, members, invites
/mgmt/v1/user …                          # profile, security, personal tokens, notifications
```

Standard REST verbs apply (`GET` list/show, `POST` create, `PATCH` update, `DELETE` delete), with action endpoints where the domain needs them. Highlights:

### Content workflow

| Endpoint | Purpose |
| --- | --- |
| `POST …/contents/{content}/publish` | Publish (optionally with message) |
| `POST …/contents/{content}/unpublish` | Take offline |
| `POST …/contents/{content}/schedule` | Schedule publication |
| `POST …/contents/{content}/move` | Re-parent/reorder |
| `POST …/contents/bulk-create` | Create many entries at once |
| `POST …/contents/tree-operations` | Apply staged tree changes (the Canvas's apply) |
| `POST …/contents/import` / `export` | Content import/export |
| Versions: `…/versions`, `POST …/versions/{version}/restore`, `POST …/versions/assign` | History, restore, assign to release |

### Releases

Create/update releases, `POST …/commit` and `POST …/cancel` them, schedule via `publish_at`.

### Assets

Upload, `POST …/assets/{asset}/replace-file`, `POST`/`DELETE …/assets/{asset}/poster` (custom poster/thumbnail for any non-image asset; deleting restores a video's generated frames), restore versions, import/export metadata, manage folders and tags (including `POST …/asset-tags/{tag}/assign`).

### AI endpoints

Streaming endpoints for content generation and translation (`POST …/translate/stream`, `POST …/meta-tags/stream`, `POST …/content-interaction/stream`, canvas planning via `POST …/content-tree-interaction/stream`, data source translation via `…/entries/translate-missing-dimensions/stream`). These stream results incrementally and require AI to be enabled for the space.

### Operations

Search reindex (`POST …/search/reindex`), redirects import/export and stat resets, icon import, backups, migrations, subscription management, audit logs, presence endpoints for collaboration.

## Reference

The complete, always-current endpoint list with request/response schemas is the generated OpenAPI spec — see [Generated OpenAPI specs](openapi.md). Generate it on your instance with:

```bash
php artisan docs:generate --prefix=mgmt/v1
```
