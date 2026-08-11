# b10cks Automations Guide

Validated against the CMS source (ValidatesActionDefinition, ValidatesTriggerDefinition, AutomationTriggerController).
Read this before creating automation actions or automations via `b10cks_mgmt_call`.

---

## Model

Two pieces, created separately and linked by id:

1. An **action** (`automations.createAction`) is a reusable delivery target — a webhook URL, an email recipient list, or a void no-op for testing. Actions own their credentials (`secrets`, write-only).
2. An **automation** (`automations.create`) binds a **trigger** to one action, optionally guarded by conditions and execution limits.

Typical workflow:

```
automations.listActions          → reuse an existing target if one fits
automations.createAction         → otherwise create the delivery target
automations.getTriggerCatalog    → discover tables/columns for record triggers
automations.create               → bind trigger → action
automations.trigger              → test manual automations (see content actions)
automations.listExecutions       → verify the run: status, context, result, error
```

---

## Actions

`automations.createAction` payload: `{ name, description?, type, config, secrets?, is_active? }`

### `type: "webhook"`

```jsonc
{
  "name": "Purge CDN",
  "type": "webhook",
  "config": {
    "url": "https://cdn.example.com/purge",        // http(s); may contain {{ placeholders }}
    "method": "POST",                              // GET|POST|PUT|PATCH|DELETE|HEAD
    "headers": { "Authorization": "Bearer {{ secret.CDN_TOKEN }}" },
    "parameters": { "tags": "{{ cache_tags }}" },  // = the JSON body (query params for GET)
    "timeout_seconds": 15                          // 1–120
  },
  "secrets": { "CDN_TOKEN": "..." }
}
```

- `headers` / `parameters` must be **flat maps of scalars**; keys must match `[A-Za-z0-9_.-]+`.
- There is no fixed webhook envelope — `parameters` *is* the body. Build exactly what the receiving endpoint expects.
- Non-2xx responses mark the execution failed (replayable). Redirects are not followed; URLs resolving to private networks are rejected (SSRF guard, applied after placeholder substitution).

### `type: "email"`

```jsonc
{
  "name": "Notify editors",
  "type": "email",
  "config": {
    "to": ["ops@example.com"],           // required, non-empty; entries: valid email or contains {{
    "cc": [], "bcc": [], "reply_to": [],
    "subject": "Published: {{ content.title }}",   // required
    "body": "See {{ record.full_slug }}"           // required
  }
}
```

### `type: "void"`

`config: { "message": "..." }` — logs only. Use it to test trigger logic before wiring a real target.

### Secrets

`secrets` is a write-only key/value map, stored encrypted; reads only ever return `has_secrets` + `secret_keys`. Reference values in templates as `{{ secret.KEY }}`. On `updateAction`, sent secrets merge into the existing map; remove keys with `clear_secret_keys: ["KEY"]`.

---

## Automations

`automations.create` payload: `{ name, description?, action_id, trigger: { type, config }, is_active?, execution_limit? }`

### Trigger types and their config

| `trigger.type` | `trigger.config` |
| --- | --- |
| `on_insert` / `on_delete` | `table` (required, from `getTriggerCatalog`) |
| `on_update` | `table` (required), `watch_columns?` (subset of the table's columns) |
| `content_published` / `content_unpublished` | none required |
| `time_based` | `schedule` (cron, required), `timezone?` (IANA id) |
| `manual` | none — or `table: "contents"` + `block_ids?` for a **content action** (below) |

Every trigger config may also carry:

- `payload` — static key/value object merged into the execution context.
- `conditions` — array of `{ path, operator, value? }` rules; all must match for the automation to fire. Operators: `eq ne contains gt gte lt lte in nin exists empty`. Paths are dot notation into the context, e.g. `record.block_id`, `changes.published_at.after`, `changed_fields`.

### Content actions (manual automations in the content tree)

A manual automation with `config.table: "contents"` becomes a **content action**: editors see it in the content tree's right-click *Actions* submenu and run it against a single entry. Restrict where it is offered with `config.block_ids` (block **IDs** from `blocks.list`; empty/omitted = all block types — the server also rejects mismatched entries on trigger).

Trigger it programmatically with `automations.trigger` and a `content_id`:

```jsonc
{ "operation": "automations.trigger", "spaceId": "…", "automationId": "…",
  "payload": { "content_id": "01JW…" } }
```

The execution context then contains the full entry (`record`, `content` with a `title` alias, `record_id`, `space`, `actor`) with `operation: "manual"` — templates and conditions behave exactly as on record-based triggers. Extra `payload` keys are merged on top. Without `content_id`, a manual trigger is payload-only.

Only `manual` automations can be triggered via this operation; the automation and its action must be active, and the execution limit must not be exhausted.

### Template placeholders (quick reference)

Any string in the action config resolves `{{ path }}` against the execution context:

- `{{ record.* }}` — the row (contents also expose `{{ content.title }}` aliasing `record.name`)
- `{{ previous.* }}`, `{{ changes.COLUMN.before|after }}`, `{{ changed_fields }}`
- `{{ cache_tags }}` — content entries only: the entry's cache tags (Config tab)
- `{{ space.id }}`, `{{ space.name }}`, `{{ actor.id }}`, `{{ triggered_at }}`
- `{{ secret.KEY }}` — from the action's secrets

Arrays/objects are JSON-encoded, `null` becomes empty, unresolved placeholders stay verbatim.

---

## Recipes

### Cache clear as a content action

Lets editors purge the CDN for exactly one entry from the content tree.

```jsonc
// 1. automations.createAction
{ "spaceId": "…", "payload": {
  "name": "CDN purge",
  "type": "webhook",
  "config": {
    "url": "https://cdn.example.com/purge",
    "method": "POST",
    "headers": { "Authorization": "Bearer {{ secret.CDN_TOKEN }}" },
    "parameters": { "tags": "{{ cache_tags }}", "path": "{{ record.full_slug }}" }
  },
  "secrets": { "CDN_TOKEN": "…" }
}}

// 2. automations.create — reuse the returned action id
{ "spaceId": "…", "payload": {
  "name": "Clear cache",
  "action_id": "<action id>",
  "trigger": { "type": "manual", "config": { "table": "contents" } },
  "is_active": true
}}
```

Add `"block_ids": ["<block id>", …]` to the trigger config to offer it only on specific content types.

### Purge on publish (no manual step)

Same action; trigger `{ "type": "content_published", "config": {} }`. Publishing an entry then purges exactly the routes that render it.

### Notify only for one content type

Add a condition instead of (or in addition to) `block_ids` semantics on event triggers:

```jsonc
"trigger": { "type": "content_published", "config": {
  "conditions": [ { "path": "record.block_id", "operator": "eq", "value": "<block id>" } ]
}}
```

### Nightly digest

`"trigger": { "type": "time_based", "config": { "schedule": "0 2 * * *", "timezone": "Europe/Vienna" } }`

---

## Verifying and operating

- `automations.listExecutions` (filter by `automation_id`, `status`) — every run with context, result, error, timing.
- `automations.replayExecution` — re-run a past execution with its original context after fixing the target.
- `execution_limit` on the automation caps total runs (`remaining_executions` in responses).
- Deleting an action is blocked while automations reference it; detach or delete the automations first.
