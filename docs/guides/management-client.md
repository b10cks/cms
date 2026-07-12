---
description: "Script the b10cks Management API with the typed @b10cks/mgmt-client: content workflows, assets, releases, automations."
---

# Management Client

[`@b10cks/mgmt-client`](https://www.npmjs.com/package/@b10cks/mgmt-client) is the typed TypeScript client for the [Management API](../api/management-api.md) — the write side of b10cks. Use it for migrations, imports, CI automation, custom tooling: everything the admin UI can do, scripted.

```bash
bun add @b10cks/mgmt-client
```

```ts
import { ManagementClient } from '@b10cks/mgmt-client'

const client = new ManagementClient({
  baseUrl: 'https://api.b10cks.com', // or your self-hosted instance
  token: process.env.B10CKS_PAT!,    // personal access token — acts with your permissions
})

const spaces = await client.spaces.list()
const blocks = await client.blocks.list(spaceId, { search: 'article' })
```

Optional config: `timeout` (ms, default 30 000) and `headers` merged into every request.

## Resources

Every Management API resource hangs off the client as a typed group:

| Property | Covers |
| --- | --- |
| `client.users` | Profile, settings, personal access tokens, invites |
| `client.teams` | Teams, members, invites, SAML, space blueprints, space roles |
| `client.spaces` | Spaces, members, subscriptions, AI settings, backups, migrations, audit logs, presence |
| `client.blocks` / `blockFolders` / `blockTags` | Block schemas, templates, versions, organization |
| `client.contents` | Entries, tree operations, versions, publishing, scheduling |
| `client.comments` | Comments, reactions, resolve/unresolve |
| `client.assets` / `assetFolders` / `assetTags` | Assets (with file upload), organization |
| `client.redirects` | Redirect rules, hit stats, import/export |
| `client.dataSources` | Sources, entries, import/export, AI translation |
| `client.automations` | Actions, triggers, executions, stats |
| `client.releases` | Release lifecycle (create → assign → commit → publish) |
| `client.tokens` | Space-scoped Data API tokens |
| `client.ai` | Models, translation, meta-tag generation, streaming |
| `client.system` / `client.provider` | Health, config, plans; provider stats & notes |

## Common workflows

### Create and publish content

```ts
const content = await client.contents.create(spaceId, {
  name: 'Hello World',
  slug: 'hello-world',
  block_id: articleBlockId,
  language_iso: 'en',
  content: { title: 'Hello World', body: richTextDoc },
  // Translations can ride along in the same call:
  translations: [
    { name: 'Hallo Welt', slug: 'hallo-welt', language_iso: 'de', content: { title: 'Hallo Welt' } },
  ],
})

await client.contents.publish(spaceId, content.data.id)
await client.contents.schedule(spaceId, otherId, { scheduled_at: '2026-09-01T08:00:00Z' })
```

Updates accept a `message` — it becomes the version's commit message in the [content history](../concepts/versions-and-publishing.md):

```ts
await client.contents.update(spaceId, contentId, { content: { title: 'Better title' }, message: 'SEO pass' })
```

### Restructure the tree in one batch

```ts
await client.contents.treeOperations(spaceId, {
  operations: [
    { type: 'create', temp_id: 'tmp-1', block_id: pageBlockId, name: 'About' },
    { type: 'move', ids: [contentA, contentB], parent_id: newParentId },
    { type: 'delete', ids: [obsoleteId] },
  ],
})
```

### Upload assets

```ts
// Browser
await client.assets.create(spaceId, { file: fileInput.files[0], name: 'hero.jpg' })

// Node.js
import { readFileSync } from 'fs'
await client.assets.create(spaceId, {
  file: readFileSync('./hero.jpg'),
  filename: 'hero.jpg',
  mime_type: 'image/jpeg',
})
```

### Run a release

```ts
const { data: release } = await client.releases.create(spaceId, { name: 'Q3 Launch' })
await client.releases.assignVersion(spaceId, release.id, { version_id: versionId })
await client.releases.commit(spaceId, release.id)   // lock for review
await client.releases.publish(spaceId, release.id)  // everything goes live together
```

## Pagination

List endpoints return a `PaginatedResponse<T>`:

```ts
const result = await client.contents.list(spaceId, { page: 1, per_page: 50 })
result.data              // items
result.meta.total        // total across all pages
result.links.next        // next page URL, or null
```

## Error handling

```ts
import { ManagementApiError } from '@b10cks/mgmt-client'

try {
  await client.contents.publish(spaceId, contentId)
} catch (error) {
  if (error instanceof ManagementApiError) {
    error.statusCode // HTTP status
    error.message    // human-readable
    error.response   // raw API response body
  }
}
```

## Types

All resource types are exported from the package root (`Block`, `Content`, `ContentVersion`, `Release`, `Automation`, `PaginatedResponse`, …) — no separate `@types` package needed.

## Related

- [Management API](../api/management-api.md) — the REST surface underneath, plus the [OpenAPI reference](../api/reference/management-api.md)
- [CLI](cli.md) — prebuilt commands for the common workflows
- [MCP server](mcp-server.md) — the same surface for AI assistants
