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

Assets, asset folders and tags, data entries and blocks go live when written, so changes to them stay proposals. `…/review` lists them in groups, and `…/review/accept` and `…/review/reject` decide them. Their deterministic violations (banned wording, protected names) need a correction or a recorded override reason. On content these findings block nothing: they are stored on the item for whoever publishes. A hint about missing required wording (`missing_term`) goes to the agent in its tool result only. It is never stored or listed.

Translation plans can include metadata generated from the translated draft. Translation memory learns only from what a person published.

Grouped operation questions retain completed work and reopen only answered outputs.

The same lifecycle is available through the CMS's MCP registry. Tokens carry their user's permissions, so a client with approval rights can approve or extend a budget. Client integrations must obtain the editor's approval before making those calls. The older streaming and immediate asset-classification endpoints keep their existing behavior; use operations when the deliverable must be accounted for per field and language.

Search reindex (`POST …/search/reindex`), redirects import/export and stat resets, icon import ([both streamable](#streaming-imports)), backups, migrations, subscription management, audit logs, presence endpoints for collaboration.


### Glossary concept rows

`…/ai/glossaries/{glossary}/concept-rows` reads and writes a glossary as one row per meaning, for spaces and teams. A row's `id` is its concept id. Every write to a glossary goes through one writer: these endpoints, imports of every format, the older term endpoints below and accepted AI suggestions.

A row holds `key`, `keep_exact`, `note`, `coverage` and `languages`, which has per locale `use`, `avoid` and `also`. A language with `avoid` and `use: null` is a ban. `note` is one per row: a write reaches all its languages. A new or changed note over 200 characters returns 422 `note_too_long`, on every write path, and a longer older note stays readable and may be sent back unchanged. Omitted fields and languages keep their value, and a language goes only through `remove_languages`.

A change may send `base`, the row's `keep_exact`, `note` and `languages` as you read them. If a field this write changes has moved since then, it returns 409 `row_changed`, with `changes` naming each `field` and `locale`, and nothing is saved. Fields it does not change still merge, and writing the value already stored replaces nothing. Without `base`, the last write wins.

A wording another concept uses in that locale returns 409 `wording_conflict`, naming the concept. A language with neither Use nor Avoid returns 422 `term_required`, and a Use its own Avoid would flag returns 422 `use_avoided`, with `locale`, `wording` and `avoid`. That reads the check's own rule: case and a `*` count, so `Cart` with Avoid `cart` is fine with `keep_exact` and refused without, and `Händler` with Avoid `Händler*` is always refused. An older row that already holds such a pair keeps it and takes other edits until that pair changes. A language code that is not valid returns 422 `language_invalid`. Nothing changes.

`GET …/concept-rows/{row}` returns one row. A list takes `search` and `missing`, and `facets` in its response covers the whole glossary: `total`, the locales, and per locale in `missing` how many rows have no term there.

`POST …/concept-rows/bulk` deletes selected rows in one transaction. `POST …/glossary-check` tries a text against saved wording without a provider call: it returns the activated rows with match offsets in UTF-16 code units, the `instruction` the agent would read and, if `output` is sent, the findings. `scope` is `glossary`, this one glossary and the default, or `space`, what a run in this space uses: every enabled glossary, inherited ones included. A team has no runs, so `space` is a 422 there.

`…/ai/glossaries/{glossary}/terms` is the older shape the published SDK still calls, one language of a row at a time. `translations` are the row's other languages that have a Use, and a wording another row holds is a 409 like anywhere else. `source_locale` and the `translations` keys come back normalized, so `de-AT` reads as `de-at`. An `id` from before the glossary was normalized still reaches the same language.

`do_not_translate` is Keep exact. `case_sensitive` stays its own setting here, as it always was: it decides whether Use and Avoid match with exact case and allows translation, and changing one leaves the other as it reads. A row that never set it follows Keep exact. The row endpoints and the new sheet have no such field and leave it alone, except that changing `keep_exact` there goes back to following it.

A `translations` map holds Use and nothing else, so it changes Use only. Sending back what `GET` returned changes nothing. A language it leaves out loses its Use: it stays as a ban if it holds Avoid, stays whole if it holds Also written as and no Avoid, and goes if it holds neither. Remove a language outright through `remove_languages` or `DELETE`. A `source_locale` naming a language the row already has returns 409 `language_conflict`, and a term that moves language gets a new `id`. `search` matches what `term` shows, a ban's first Avoid wording included.

A glossary inherited from a team reads and exports the same, and returns 403 on row writes and 404 on term writes and imports. `meta` holds `read_only`, `default_locale`, `available_locales` and whole-glossary `facets`. In a space, `available_locales` lists the enabled languages, default first, then stored ones, flagged `unused` when no enabled language can use them.

### Streaming imports

Asset metadata, redirects, data entries, icons, content translations and glossary terms all import through `POST …/import` with a multipart `file`.

Send `Accept: text/event-stream` for large files. The response is server-sent events: `status` events whose `message` is JSON `{processed, total}`, then a `done` event carrying the result in `data`, or an `error` event with `message`. A file that fails validation is an `error` event on a stream and a 422 otherwise.

Without that header the result is one JSON response, which has to finish within the request time limit (30 seconds through the CDN).

A glossary CSV or XLSX applies directly in the format described in [Glossary sheets](../guides/glossary-sheet.md). Its result adds `summary.added`, `summary.updated`, `summary.unchanged` and `warnings`, `errors` hold `row`, `column` and `message`, and unknown columns are listed once in `ignored_fields`. A row with no change counts as unchanged. Each such import writes one audit log entry with its actor, file name and counts. A header with `source_locale` keeps the older term import, and it and TBX return the plain result. In TBX, preferred, deprecated and admitted terms map to `use`, `avoid` and `also`. In both of those, a line or entry updates the row holding its first language's wording. Another of its languages finds a row only if that row has no wording in the first language. Otherwise the line is new and a wording it shares with that row is a row error, so nothing it did not name is replaced. In both of those, a language code that is not valid or wording over 255 characters is an error on that row, and the rest applies. Older `case_sensitive` columns in a `source_locale` file still apply.

`GET …/ai/glossaries/{glossary}/export?format=csv|xlsx|tbx` downloads the glossary, and `&template=1` the columns with one made up row (`csv` and `xlsx` only).


The complete, always-current endpoint list with request/response schemas is the generated OpenAPI spec — see [Generated OpenAPI specs](openapi.md). Generate it on your instance with:

```bash
php artisan docs:generate --prefix=mgmt/v1
```
