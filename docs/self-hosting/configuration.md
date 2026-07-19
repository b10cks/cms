---
description: "The .env configuration reference for self-hosted b10cks: application, database, cache, real-time, storage, search, mail, and AI."
---

# Configuration

All configuration happens through the standard Laravel `.env` file. This page covers the keys that matter for a production instance; billing has [its own page](plans-and-pricing.md).

## Application

```bash
APP_URL=https://cms.example.com
APP_DOMAIN=cms.example.com
SANCTUM_STATEFUL_DOMAINS=cms.example.com
APP_ENV=production
APP_DEBUG=false
```

## Database

```bash
DB_CONNECTION=mysql
DB_HOST=…
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…
```

Each space can optionally run in its **own isolated database** — the management database stores users, teams, spaces, and billing, while space databases hold content. Space database provisioning requires credentials allowed to create databases; see [Spaces](../concepts/spaces.md#isolated-databases).

## Cache, queues, sessions

```bash
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=…
```

`QUEUE_CONNECTION=sync` works for evaluation but blocks requests on every background job — use Redis in production.

## Real-time (Reverb)

```bash
BROADCAST_DRIVER=reverb
REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=cms.example.com
REVERB_SCHEME=https
```

Any Pusher-protocol-compatible service works as an alternative to self-hosted Reverb.

## File storage

```bash
FILESYSTEM_DISK=local          # or s3 / gcs
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=…
```

Assets (uploads and generated image transformations) live on this disk. S3 or GCS is recommended for anything beyond a single-server setup.

## Search

```bash
# Default: MySQL FULLTEXT — no extra infrastructure
OPENSEARCH_HOST=…              # optional, enables the opensearch driver
OPENSEARCH_USERNAME=…
OPENSEARCH_PASSWORD=…
```

The driver is chosen **per space** in its settings; after switching, trigger a reindex from the space settings.

## Mail

Standard Laravel mail configuration (`MAIL_*`) — used for invites, notifications, and password resets.

## AI (optional)

```bash
AI_MODE=space                  # AI configuration is managed per space
AI_DEFAULT_DRIVER=openrouter
OPENROUTER_ENABLED=true
OPENROUTER_MANAGEMENT_KEY=…    # provisions per-space keys
```

AI features (generation, translation, meta tags, asset classification) are opt-in and configured per space in **Settings → AI**.

## Billing (optional)

Plan-based subscriptions via LemonSqueezy for multi-tenant installations. Single-team installs can ignore this — spaces work without a subscription provider. See [Plans & pricing](plans-and-pricing.md).
