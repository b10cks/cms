---
description: "Spaces are isolated content environments with their own database, team, languages, and settings."
---

# Spaces

A **space** is an isolated content environment — the top-level unit of organization in b10cks. Each space has its own content tree, block library, assets, data sources, redirects, icons, team, settings, and access tokens. A typical setup is one space per website, app, or brand.

## Isolation model

b10cks separates a shared **management database** (users, teams, spaces, subscriptions) from **per-space content storage**. Content-related tables — blocks, contents, versions, assets, data sources, redirects, icons — belong to the space. On self-hosted installations a space can run in its **own dedicated database**, giving hard isolation between projects and making per-space backup and migration straightforward.

Two consequences worth knowing:

- All space records use ULID primary keys plus a stable `external_id`, which acts as a cross-space identity. This is what makes [space migrations](../ui/settings.md#migrations) idempotent — re-running a migration matches records by `external_id` instead of duplicating them.
- Nothing is shared between spaces: block definitions, assets, and tokens from one space are invisible to another. To move content between spaces, use the migration tool or import/export.

## Space settings

Settings that shape how the rest of the system behaves (all editable in **Settings**):

| Setting | Effect |
| --- | --- |
| **Languages** | The enabled languages and the default language. Drives translatable fields, the Data API's `language` parameter, and fallback behavior. See [Internationalization](internationalization.md). |
| **Search driver** | `mysql` (FULLTEXT, zero infrastructure) or `opensearch`. Switching drivers requires a reindex, triggered from settings. |
| **Content sorting** | Default ordering of children in the content tree; individual entries can override it. |
| **Visual editor** | The preview URL(s) your frontend is served from. See [Live preview](../guides/live-preview.md). |
| **Access tokens** | Data API tokens. See [Access tokens & caching](access-tokens.md). |
| **AI** | Optional per-space AI provider configuration and spend limits. |

## Team and roles

Access to a space is granted per user with space-scoped roles, or inherited through a **team** that owns the space. People management (invites, roles) lives in **Settings → People**; see the [user guide](../ui/settings.md#people--roles).

## Space lifecycle

A space has a state; the Data API only serves spaces in the `live` state. Suspended or archived spaces keep their content but stop delivering it.

## Related

- [Quickstart](../getting-started/quickstart.md) — create your first space
- [Backups & migrations](../ui/settings.md) — snapshot a space or move it between instances
