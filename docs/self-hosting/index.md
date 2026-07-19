---
description: "Run b10cks on your own infrastructure: what's involved, requirements, and the moving parts of a production deployment."
---

# Self-hosting b10cks

b10cks CMS is open source (AGPL-3.0) and built as a Laravel application with a Vue admin UI. It runs anywhere PHP 8.4+ runs — a VPS, Docker, or any cloud provider. Self-hosting gives you the full product: every feature in these docs works on your own instance, with no license keys or feature gates.

This section is for the person **operating** an instance. If you're building a site against b10cks Cloud (or someone else's instance), you want the [guides](../getting-started/introduction.md) instead.

## What's in this section

| Page | Covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install steps, the long-lived processes, and upgrades |
| [Configuration](configuration.md) | The `.env` reference: database, storage, real-time, search, AI, mail |
| [Plans & pricing](plans-and-pricing.md) | Optional billing: public plans, LemonSqueezy, custom/agency plans, quota overrides |

## The moving parts

A production deployment runs three long-lived processes plus the scheduler:

| Process | Purpose |
| --- | --- |
| App server (FrankenPHP/Octane or PHP-FPM) | HTTP: admin UI, Data API, Management API |
| Queue worker | Background jobs: publishing, backups, imports/exports, asset processing, AI |
| WebSockets (Reverb) | Real-time collaboration, presence, notifications |
| Scheduler (cron) | Recurring maintenance: usage rollups, subscription sync, cleanup |

Alongside those, an instance uses a **management database** (users, teams, spaces, billing) and per-space content storage — optionally one **isolated database per space** for hard project isolation (see [Spaces](../concepts/spaces.md#isolated-databases)).

## Single team or platform?

Both are first-class:

- **Single team / company instance** — skip billing entirely. Spaces work without a subscription provider, and quotas are unlimited unless you configure plans.
- **Platform / agency instance** — connect [LemonSqueezy](plans-and-pricing.md) to sell plans, and use [custom plans and quota overrides](plans-and-pricing.md#custom-plans) for negotiated deals.
