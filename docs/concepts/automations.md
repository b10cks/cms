---
description: "Webhooks and automations from the integration side: triggers, context, templates, secrets, and delivery semantics."
---

# Automations & Webhooks

Automations connect b10cks to the outside world: *when content is published → call the deploy hook*, *when an asset changes → purge the CDN*, *every night → email a digest*. This page covers them from the integration side — payloads, templates, secrets, and delivery semantics. The editing UI is described in the [automations user guide](../ui/automations.md).

## Model

Two pieces, managed separately:

- An **action** is a reusable delivery target — a webhook URL, an email recipient list, or a void no-op for testing. Actions own their credentials ([secrets](#secrets), encrypted at rest), so several automations can share one destination without duplicating keys.
- An **automation** binds a **trigger** to an action, optionally guarded by conditions and execution limits.

### Triggers

| Trigger | Fires |
| --- | --- |
| `content.published` / `content.unpublished` | An entry goes live / offline |
| On insert / update / delete | Row-level changes of a chosen resource type; *on update* can watch specific columns |
| Time based | A cron expression |
| Manual | Only when run by hand (or via `automations.trigger` in the Management API) |

Triggers can carry **conditions** (fire only when the record matches rules) and a static **payload** merged into the context.

### Manual content actions

A manual automation can be marked as a **content action** (`config.table: "contents"`). It then shows up in the content tree's *Actions* context submenu, where it can be run against a single entry. Optionally restrict it to specific block types via `config.block_ids` (block IDs; empty = all) — the tree only offers matching actions per entry, and the server rejects mismatches on trigger.

Programmatically, pass `content_id` to the trigger endpoint (`POST …/automations/{id}/trigger`, also exposed as `automations.trigger` in the Management API/MCP). The execution then receives the full record context of that entry — `record` / `content` (with the `title` alias), `record_id`, `space`, `actor`, `meta` — with `operation: "manual"` and `source: "manual"`, so templates and conditions behave exactly as on record-based triggers. Any request `payload` is merged on top. Without `content_id`, manual triggering stays payload-only as before.

Triggering is guarded by its own **`automations.trigger`** ability (built-in owner, admin, and editor roles have it). It implies read access to automations — you must see what's triggerable — but not managing them; secrets stay write-only either way.

## The context

Every execution builds a context object; it's what conditions evaluate against, what template placeholders resolve from, and what's stored with the execution for inspection and replay. For record-based triggers it looks like:

```jsonc
{
  "operation": "content.published",
  "table": "contents",
  "model": "Content",
  "record_id": "01JW…",
  "record": { /* the full row after the change */ },
  "previous": { /* the row before the change, or null */ },
  "changes": { "published_at": { "before": null, "after": "2026-07-13T09:30:00Z" } },
  "changed_fields": ["published_at"],
  "space": { "id": "01JV…", "name": "Website" },
  "actor": { "id": "01JU…" },
  "cache": { "ttl": 3600, "tags": ["page", "news"] },   // content entries only —
  "cache_tags": ["page", "news"],                        // from the entry's Config tab
  "trigger": { /* the trigger definition */ },
  "triggered_at": "2026-07-13T09:30:02+00:00",
  "meta": { "table_label": "Contents", "primary_key": "id", "changed_count": 1 }
}
```

## Templates

Any string in an action's configuration — the URL, headers, parameters, email subject/body — can embed `{{ path }}`{v-pre} placeholders, resolved against the context with dot notation:

```
https://cdn.example.com/purge?tag={{ cache_tags }}
X-Entry: {{ record.full_slug }}
Published by {{ actor.id }} at {{ triggered_at }}
```

Resolution rules: any context path works (`{{ record.slug }}`{v-pre}, `{{ changes.published_at.after }}`{v-pre}); arrays and objects are JSON-encoded; `null` becomes an empty string; unresolved placeholders are left verbatim so mistakes are visible at the receiving end.

## Webhook delivery

| Config | Notes |
| --- | --- |
| `url` | `http(s)` URL, may contain placeholders |
| `method` | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, or `HEAD` |
| `headers` | Key/value map, placeholder-aware — auth headers, content types |
| `parameters` | Key/value map, placeholder-aware — sent as the JSON body (or as query parameters for `GET`) |
| `timeout_seconds` | 1–120, default 15 |

Semantics worth knowing:

- **You define the payload.** There is no fixed webhook envelope — `parameters` *is* the body, built from placeholders. Send exactly what your endpoint expects (a Slack message, a deploy-hook POST, a purge request) instead of parsing a CMS-shaped blob.
- **A non-2xx response marks the execution failed** (visible in the execution history, replayable after you fix the target).
- **Redirects are not followed**, and URLs resolving to private/internal networks are rejected — self-hosted instances aren't SSRF vectors. Templated URLs are validated *after* placeholder substitution.

## Secrets

Actions carry a `secrets` map, stored encrypted and write-only — the UI and API never return the values. Templates reference them as `{{ secret.KEY }}`{v-pre}:

```
Authorization: Bearer {{ secret.DEPLOY_HOOK_TOKEN }}
```

Rotating a secret on the action updates every automation using it.

## Pattern: targeted CDN invalidation

Content entries can define **cache tags** (entry Config tab, delivered as response headers by the [Data API](access-tokens.md#revision-based-caching)). An automation closes the loop:

1. Trigger: `content.published`, 2. Action: webhook to your CDN's purge API, 3. Parameters: `{ "tags": "{{ cache_tags }}" }`{v-pre}.

Publishing an entry then purges exactly the routes that render it — no full-site invalidation, no polling.

## Executions, limits, replay

Every run is recorded — status, timing, the full context, the result or error. **Replay** re-runs a past execution with its original context: fix the receiving endpoint, replay the failure, done. **Execution limits** (n of m) cap runaway automations before they hammer a target. Manage automations programmatically via the [Management API](../api/management-api.md) (`automations.*`), the [management client](../guides/management-client.md), or the [MCP server](../guides/mcp-server.md).

## Related

- [Automations user guide](../ui/automations.md) — creating actions and automations in the UI
- [Content](content.md#per-entry-settings) — per-entry cache TTL and tags
