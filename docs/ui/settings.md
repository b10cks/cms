---
description: "Space settings: access tokens, people and roles, configuration, AI, backups, migrations, subscription, and usage."
---

# Space Settings

**Settings** collects everything that configures a space. This page walks through each section.

## General

- **Space** — name, icon (shown across the app), and the read-only space ID.
- **Content** — default content block for new entries, whether hidden blocks are stripped from Data API responses, manual content sorting (drag-and-drop ordering vs. alphabetical), and **sitemap extraction** (which block types appear in the public sitemap feed and where their SEO meta lives).
- **Danger zone** — deleting the space permanently removes all content, settings, and user data (type-to-confirm).

## Access tokens

Create and revoke [Data API tokens](../concepts/access-tokens.md). Each token shows creation, expiry, last-used time, and usage count. **The token value is shown once at creation — copy it then.**

## Editor

- **Environments** — named preview URLs (e.g. *Local* → `https://localhost:3000/`, *Staging* → …) used by the content editor's live preview. One is the space default; editors can pick a personal preference.
- **Visual editor** — enable/disable the WYSIWYG editing experience.

## Internationalization

Languages (code + label), the default language, per-language **mode** (overlay vs. independent) and **fallback**, the slug strategy, and **site locales** (URL segments mapped to languages). Concepts: [Internationalization](../concepts/internationalization.md).

## Asset library

Configure the metadata schema for [assets](../concepts/assets.md): custom fields (key + label, optionally required — required applies to the default language only), and the optional **Rights & Licensing** field group (copyright holder, license type, usage restrictions, expiry).

These are the space-wide defaults — individual asset **folders** can override them (enable/disable fields, change required-ness) and add folder-only fields. See [per-folder metadata rules](../concepts/assets.md#per-folder-metadata-rules).

## Shares

Every public asset **share link** in the space, in one table — its source (collection, folder, or selection), password state, view and download counts, expiry, and status. Copy, open, edit, revoke, or delete any link from here. Shares are created from the [asset manager](assets.md#sharing-assets); this is the central place to audit and clean them up. See [sharing & downloads](../concepts/assets.md#sharing--downloads).

## People

Manage who has access to the space:

- **Members** — everyone with access, with role assignment, filtering, and (bulk) removal.
- **Pending invites** — outstanding invitations; resend or revoke.

Roles are space-scoped; spaces owned by a team also inherit team-level access ([Account, teams & spaces](account.md)).

## AI

Enable/disable AI features (content generation, translation, canvas planning, smart search), pick the AI model (with provider, context window, and token-multiplier info), and monitor usage against the plan's AI quota for the billing period.

## Actions & Automations

Reusable delivery actions (webhooks, email, void) and the automations that trigger them — documented in [Automations](automations.md).

## Backups

Create space backups (name, description, optional AES-256 password-protected ZIP, expiry date, email recipients) and manage the backup history with state, progress, and size. Backups run in the background and can be downloaded or delivered to the recipients.

## Migrations

Push content **from this space into another space on the same instance** — direct database-to-database, no export files:

- **Scope** — choose what to migrate: blocks (schemas), block templates, content entries, asset metadata, data sources & entries, redirects. (Migrating content without its blocks risks missing block types in the target.)
- **Conflict strategy** — *Skip existing* (only create missing records), *Overwrite* (source wins), or *Merge newer* (overwrite only when the source is more recent).
- Migrations are **idempotent**: records match across spaces via their stable `external_id`, so re-running updates rather than duplicates.
- **Serial reservations travel with the content**: the counters behind [serial fields](../concepts/fields.md#serial) continue in the target space instead of restarting at one. A reservation that collides with one the target already holds is reported as an error rather than aborting the migration.

Progress and results are tracked per migration (pending → processing → completed/failed).

## Subscription, usage & invoices

On installations with billing enabled: the current plan, quota usage, plan changes, **Usage & billing** history per plan period (traffic trends, period events like renewals/upgrades), and invoice access.
