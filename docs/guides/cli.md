---
description: "The b10cks CLI: authentication, space and team commands, and TypeScript type generation from block schemas."
---

# CLI

[`@b10cks/cli`](https://www.npmjs.com/package/@b10cks/cli) brings common b10cks workflows to the terminal — inspecting spaces and teams, scripting content operations, and generating TypeScript types from your block schemas. It talks to the [Management API](../api/management-api.md) via [`@b10cks/mgmt-client`](management-client.md), so it acts with your permissions.

## Install

```bash
bun add -g @b10cks/cli     # or npm install -g / pnpm add -g
```

Or run without installing:

```bash
bunx @b10cks/cli <command>   # or npx @b10cks/cli
```

## Authentication

```bash
b10cks login
```

Prompts for a **personal access token** (create one in your account security settings) and stores it in `~/.netrc` with `0600` permissions. Credentials for other hosts in an existing `~/.netrc` are never touched — if the file can't be parsed, `login`/`logout` abort instead of overwriting it. `b10cks logout` removes the stored token.

For CI or one-off runs, bypass `.netrc` with environment variables:

```bash
B10CKS_LOGIN=sanctum B10CKS_TOKEN=<your-token> b10cks spaces-list
```

Self-hosted or staging instances: point the CLI at your API with `B10CKS_API_DOMAIN` (default `https://api.b10cks.com`).

## Commands

Every command supports `--help`; most accept `--interactive` to prompt for missing values.

### Workspace

| Command | Purpose |
| --- | --- |
| `spaces-list` | List all spaces you can access |
| `spaces-create --name "My Space" --slug my-space` | Create a space (`--team-id`, `--description`, `--icon`, `--color`) |
| `spaces-hierarchy` | Print the workspace tree — teams and their spaces |
| `teams-list` | List teams with IDs and parent relationships |
| `teams-create --name "Engineering"` | Create a team (`--parent-id` creates a subteam) |
| `teams-hierarchy` | Print the team tree |

### Space resources

| Command | Purpose |
| --- | --- |
| `blocks-list <spaceId>` | Block definitions with ID, name, slug, and type |
| `contents-list <spaceId>` | Content entries with publish status |
| `releases-list <spaceId>` | Releases with status (draft / committed / published) |
| `data-sources-entries-create <spaceId> <dataSourceId> --key country --value Austria` | Add a data source entry |

### `generate-types`

Generates TypeScript interfaces from all block schemas in a space — the foundation for typed content in every [framework guide](javascript.md#typed-content):

```bash
b10cks generate-types <spaceId>
b10cks generate-types <spaceId> --out ./src/b10cks/types
```

Writes `generated.d.ts` and `index.d.ts` (default output: `./b10cks/types`). The generated interfaces cover every field type — rich text, assets, links, options, nested blocks, tables, and meta — so a `hero` block with a `headline` text field becomes:

```ts
export interface B10cksHero {
  id: string
  block: 'hero'
  headline?: string
  // …
}
```

Re-run it after schema changes (a CI step or a `postinstall`/`prebuild` script keeps types in sync with the space).

## Scripting beyond the CLI

The CLI covers the common paths; for anything else, the same API surface is available as a typed client — see [`@b10cks/mgmt-client`](management-client.md) — or directly via [REST](../api/management-api.md). AI assistants can drive it through the [MCP server](mcp-server.md).
