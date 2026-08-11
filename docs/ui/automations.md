---
description: "Setting up actions and automations: triggers, conditions, execution history, and replay."
---

# Automations

**Automations** are "when this happens, do that" rules: *when content is published → rebuild the website*, *every night → send a digest email*. They connect the CMS to the outside world — cache purges, site rebuilds, notifications, syncs — without anyone polling an API.

> Integrating with webhooks? Payload shapes, `{{ }}` templates, secrets, and delivery semantics are covered in [Automations & webhooks (concepts)](../concepts/automations.md).

Automations are built from two pieces, managed separately so delivery settings aren't duplicated:

## Actions (Settings → Actions)

An **action** is a reusable delivery step with its own credentials:

| Type | What it does |
| --- | --- |
| **Webhook** | HTTP request to a URL, with configurable headers and parameters |
| **Email** | Email delivery to a recipient list |
| **Void** | Internal no-op — useful for testing trigger logic |

Actions store **protected secrets** (API keys, signing secrets) attached to the action itself; existing secret values stay hidden and multiple automations can reuse the same destination safely. The actions table shows linked automations, execution status, and last execution; an action with linked automations can't be deleted until they're detached.

## Automations (the triggers)

An automation picks a **trigger**, links an **action**, and sets guardrails:

### Trigger types

| Trigger | Fires |
| --- | --- |
| **Content published** | When an entry transitions to published; context includes the full record, previous state, and timestamps |
| **Content unpublished** | When a published entry is taken offline |
| **On insert / update / delete** | On row-level changes of a chosen resource type; *on update* can watch specific columns |
| **Time based** | On a cron expression |
| **Manual** | Only when run by hand — from the automations page, the Management API, or the content tree (see below) |

Triggers can carry **conditions** (only fire when the record matches rules) and **payload values** — including placeholders resolved from the trigger context (e.g. the entry's slug or [cache tags](content.md#tabs) for targeted CDN invalidation).

### Content actions

A manual automation can be offered directly in the [content tree](content.md#the-content-tree): enable **Content action** on the trigger, and the automation appears in the tree's right-click menu under **Actions**, ready to run against a single entry — *send this page to translation*, *re-index this article*, *notify the team about this entry*.

- **Limit to block types** (optional) restricts where the action is offered — a "Request translation" action only on articles, not on folders. Leave it empty to offer the action on every entry. The restriction is enforced by the server too, not just hidden in the menu.
- The run receives the **full entry as context**, exactly like a content-published trigger — so templates like `{{ record.full_slug }}`{v-pre} or conditions on the record work unchanged.
- The **Actions** submenu is shown to everyone with the *Trigger automations* permission (included in the built-in owner, admin, and editor roles); it appears greyed out when no action matches the entry's block type. Triggering does not grant editing automations.

### Guardrails

Automations can be activated/deactivated and can carry **execution limits** (n of m used, remaining counter) to keep runaway triggers from hammering a target.

## Execution history

The **Execution History** view lists every run across all automations — queued, running, completed, failed — with source (trigger, schedule, manual, replay), timing, duration, the stored **context** (trigger payload) and **result**, and error details for failures. **Replay** re-runs a past execution with its original context — the fastest way to retry a broken workflow after fixing the target.
