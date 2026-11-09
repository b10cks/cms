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
/mgmt/v1/spaces/{space}/icons            # icon registry
/mgmt/v1/spaces/{space}/field-plugins    # field plugins
/mgmt/v1/spaces/{space}/automations      # automations (+ actions, executions)
/mgmt/v1/spaces/{space}/backups          # backups
/mgmt/v1/spaces/{space}/migrations       # space migrations
/mgmt/v1/spaces/{space}/…                # settings, tokens, members, invites, audit-logs, usage
/mgmt/v1/teams …                         # teams, members, invites, SAML provider,
                                         # space blueprints, custom space roles
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
| `POST …/contents/import` / `export` | Content import/export (`export` takes `fields` and `languages` comma lists, and `grid=1` for the Mass Edit selection; `import` streams progress with `Accept: text/event-stream`, see [Streaming imports](#streaming-imports)) |
| `GET …/mass-edit/fields` | Translatable fields aggregated across all block schemas, incl. nested blocks |
| `GET …/mass-edit/rows` | Paginated translation units for the selected `fields` / `languages`, filterable |
| `PATCH …/mass-edit/rows` | Write a delta of edited cells, keyed by unit id ([Mass Edit](../ui/mass-edit.md)) |
| Versions: `…/versions`, `POST …/versions/{version}/restore`, `POST …/versions/assign` | History, restore, assign to release |

### Releases

Create/update releases, `POST …/commit` and `POST …/cancel` them, schedule via `publish_at`.

### Assets

Upload, `POST …/assets/{asset}/replace-file`, `POST`/`DELETE …/assets/{asset}/poster` (custom poster/thumbnail for any non-image asset; deleting restores a video's generated frames), restore versions, import/export metadata ([streamable](#streaming-imports)), manage folders and tags (including `POST …/asset-tags/{tag}/assign`).

### AI endpoints

Streaming endpoints for content generation and translation (`POST …/translate/stream`, `POST …/meta-tags/stream`, `POST …/content-interaction/stream`, canvas planning via `POST …/content-tree-interaction/stream`, data source translation via `…/entries/translate-missing-dimensions/stream`). These stream results incrementally and require AI to be enabled for the space.

### Operations

Long-running AI work uses `…/ai/operations`. Create a draft with a frozen scope, review its plan and output requirements, then approve the plan hash, scope hash and budget. Planning, approval, resume and answer submission can start provider calls. Budget increases are explicit. Completion means the queue drained; read `counts.coverage.complete` for requested output coverage.

Once approved, an operation changes content without waiting for anyone: updates and new locale entries go straight to drafts under the actor who started it, and nothing is published. A person publishes a draft when happy, and each entry's version history is how to go back. `GET …/ai/operations/{id}/changes` lists what changed, an entry at a time, with `summary` holding the figures and entries with conflicts and flagged wording first. `GET …/review/items?target_id=` returns the items of one entry. A field someone changed after the agent read it is never overwritten: its item stays open as a `conflict` for a person, the agent is told, further writes to that field are refused until a person decides, and the rest continues. Resolve a conflict per item through `…/ai/changesets/{id}/accept` (`overwrite`) or `…/reject`. `GET …/ai/content-flags` returns open conflicts and flagged wording per entry (`release_id` limits it to a release), and `GET …/ai/content-flags/details?ids[]=` names them by field. Flagged wording clears when it leaves the field or the draft is published, and neither blocks a publish.

Assets, asset folders and tags, data entries and blocks go live when written, so changes to them stay proposals. `…/review` lists them in groups, and `…/review/accept` and `…/review/reject` decide them.

Translation plans can include metadata generated from the translated draft. Translation memory learns only from what a person published.

Grouped operation questions retain completed work and reopen only answered outputs.

The same lifecycle is available through the CMS's MCP registry. Tokens carry their user's permissions, so a client with approval rights can approve or extend a budget. Client integrations must obtain the editor's approval before making those calls. The older streaming and immediate asset-classification endpoints keep their existing behavior; use operations when the deliverable must be accounted for per field and language.

Search reindex (`POST …/search/reindex`), redirects import/export and stat resets, icon import ([both streamable](#streaming-imports)), backups, migrations, subscription management, audit logs, presence endpoints for collaboration.


### Glossary (removed)

The glossary endpoints are gone: `…/ai/glossaries`, its concept rows, the check, import and export, and glossary suggestions. Wording rules live in [style guides](#knowledge-documents) now, one per language where they differ. Nothing checks AI output against them, and translation memory reuses stored pairs as they are. The drop migration wrote each glossary as JSON to `glossary-archive/` on the transfers disk before removing the tables.

### Knowledge documents

`…/spaces/{space}/ai/knowledge` and `…/teams/{team}/ai/knowledge` hold style guides (`type: style`) and reference documents (`type: reference`). Both are a Markdown `draft` that `POST …/knowledge/{id}/publish` freezes into a revision. Each has a `description` of at most 200 characters, required on create and never empty after: one sentence on what it covers and when to read it. Runs list every reference document in scope by slug, title, locale and description, so the agent knows what exists and reads one with `read_knowledge`. Without a reference document in scope, a run gets neither `read_knowledge` nor `search_knowledge`. A style guide's description shows only in lists, since its text is always in the prompt. A `locale` scopes a document to one language: a run writing `nl-NL` reads a guide stored as `nl-NL`, else one stored as `nl`, and a guide without a locale applies to every language.

Every run in a style guide's scope reads it whole, so a style guide holds at most 20,000 characters. A create or update whose style `draft` is longer, or that turns a longer document into a style guide, returns 422 on `draft` with the limit and the draft's length, and nothing is saved. Publishing a longer draft, for example one extracted from an upload, returns the same 422 and leaves the published revision as it was. An older guide over the limit takes other edits while its draft stays unchanged. Reference documents are chunked for search and have no such limit.

### Translation memory

`…/spaces/{space}/ai/translation-memory` lists the space's translation memory: one unit per translated field of a published translation, pairing its text with the source text it was written against. AI runs reuse an exact match without a provider call, so a wrong pair keeps coming back until it is corrected or removed here. Reading needs `ai.view`, writing `ai.manage`.

A unit holds `source_text`, `target_text`, `source_locale`, `target_locale`, `origin`, `content_id` and `field_path` of the translation entry it was published in, `content` with that entry's `name`, `full_slug`, `source_id` and `trashed`, and timestamps. `origin` is `human` for text a person wrote and `ai_accepted` for AI text a person published. A list takes `source_locale`, `target_locale`, `search` over source and target text, `origin` and `content_id`, which also finds every translation of a source entry, and pages with `per_page` up to 200, most recently recorded or corrected first.

`PATCH …/translation-memory/{unit}` with `target_text` corrects a unit. The pair becomes `origin: human`, since a person wrote the text, and as the newest unit it wins an exact match at once. `DELETE …/translation-memory/{unit}` removes one, `POST …/translation-memory/bulk` with `unit_ids` and `action: delete` removes several, and an unknown id refuses the whole request. To remove every unit a filter matches, list with the filter and send the ids.

Publishing is the verification step. Each publish of a translation records its units again from what the published version holds, field by field: a corrected unit gets the entry's text back and a deleted one returns. Neither endpoint changes the entry, so fix a wrong text in the translation itself as well, or the next publish restores it. `ai:backfill-translation-memory` records from published versions the same way.

### Streaming imports

Asset metadata, redirects, data entries, icons and content translations all import through `POST …/import` with a multipart `file`.

Send `Accept: text/event-stream` for large files. The response is server-sent events: `status` events whose `message` is JSON `{processed, total}`, then a `done` event carrying the result in `data`, or an `error` event with `message`. A file that fails validation is an `error` event on a stream and a 422 otherwise.

Without that header the result is one JSON response, which has to finish within the request time limit (30 seconds through the CDN).

The complete, always-current endpoint list with request/response schemas is the generated OpenAPI spec — see [Generated OpenAPI specs](openapi.md). Generate it on your instance with:

```bash
php artisan docs:generate --prefix=mgmt/v1
```
