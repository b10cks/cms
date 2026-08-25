---
description: "Run b10cks on your own infrastructure: what's involved, requirements, and the moving parts of a production deployment."
---

# Self-hosting b10cks

b10cks CMS is open source (AGPL-3.0) and built as a Laravel application with a Vue admin UI. It runs anywhere PHP 8.5+ runs — a VPS, Docker, or even shared webhosting. Self-hosting gives you the full product: every feature in these docs works on your own instance, with no license keys or feature gates.

On a machine with Docker, one command gets you a running instance:

```bash
curl -fsSL https://get.b10cks.com | sh
```

Two ready-made distributions are published for every release: the [`b10cks/cms` Docker image](https://hub.docker.com/r/b10cks/cms) (with a `docker-compose.yml` in the repo) and a **webhost package** on [GitHub releases](https://github.com/b10cks/cms/releases) — a pre-built archive for traditional hosting with a browser-based installer. See [Installation](installation.md).

This section is for the person **operating** an instance. If you're building a site against b10cks Cloud (or someone else's instance), you want the [guides](../getting-started/introduction.md) instead.

## What's in this section

| Page | Covers |
| --- | --- |
| [Installation](installation.md) | Requirements, install steps, the long-lived processes, and upgrades |
| [Configuration](configuration.md) | The `.env` reference: database, storage, real-time, search, AI, mail |
| [Backup & restore](backup.md) | What to back up, how to dump the databases, and how to restore |
| [Plans & pricing](plans-and-pricing.md) | Optional billing: public plans, LemonSqueezy, custom/agency plans, quota overrides |

## The moving parts

A production deployment runs three long-lived processes plus the scheduler:

| Process | Purpose |
| --- | --- |
| App server (FrankenPHP/Octane or PHP-FPM) | HTTP: admin UI, Data API, Management API |
| Queue worker | Background jobs: publishing, backups, imports/exports, asset processing, AI |
| WebSockets (Reverb) | Real-time collaboration, presence, notifications (optional) |
| Scheduler (cron) | Recurring maintenance: usage rollups, subscription sync, cleanup |

Alongside those, an instance uses a **management database** (users, teams, spaces, billing) and per-space content storage — optionally one **isolated database per space** for hard project isolation (see [Spaces](../concepts/spaces.md#isolation-model)).

## Single team or platform?

Both are first-class:

- **Single team / company instance** — skip billing entirely. Spaces work without a subscription provider, and quotas are unlimited unless you configure plans.
- **Platform / agency instance** — connect [LemonSqueezy](plans-and-pricing.md) to sell plans, and use [custom plans and quota overrides](plans-and-pricing.md#custom-plans) for negotiated deals.
