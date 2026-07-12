---
description: "Run b10cks on your own infrastructure: requirements, installation, configuration, and a production checklist."
---

# Self-hosting

b10cks CMS is a Laravel application with a Vue admin UI. It runs anywhere PHP 8.4+ runs — a VPS, Docker, or any cloud provider. This page covers a production-oriented setup; for local development see the [README on GitHub](https://github.com/b10cks/cms#getting-started).

## Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.4+ (with `bcmath`, `exif`, `gd`/`imagick`, `intl`, `pdo_mysql`, `pcntl`, `redis` extensions) |
| Database | MySQL 8.0+ / MariaDB (PostgreSQL and SQLite also supported) |
| Node / Bun | Node 20+ and Bun 1.0+ (build-time only, for the admin UI) |
| Composer | 2.5+ |
| Redis | Optional but recommended — caching, queues, real-time |
| ffmpeg / libvips | Optional — video previews and fast image processing |
| OpenSearch | Optional — relevance-tuned full-text search at scale |

## Installation

```bash
git clone https://github.com/b10cks/cms.git
cd cms
composer install --no-dev
bun install
bun run build
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Serve `public/` with your web server of choice. The repository ships a `Dockerfile` based on [FrankenPHP](https://frankenphp.dev/) (Laravel Octane) with supervisor, ffmpeg, and libvips preinstalled — a good starting point for container deployments.

## Processes

A production deployment runs three long-lived processes:

| Process | Command | Purpose |
| --- | --- | --- |
| App server | `php artisan octane:frankenphp` (or classic PHP-FPM + nginx) | HTTP: admin UI, Data API, Management API |
| Queue worker | `php artisan queue:work` | Background jobs: publishing, backups, imports/exports, asset processing, AI |
| WebSockets | `php artisan reverb:start` | Real-time collaboration, presence, notifications |

Plus the Laravel scheduler in cron:

```
* * * * * php /path/to/cms/artisan schedule:run >> /dev/null 2>&1
```

## Key configuration (`.env`)

### Application

```bash
APP_URL=https://cms.example.com
APP_DOMAIN=cms.example.com
SANCTUM_STATEFUL_DOMAINS=cms.example.com
APP_ENV=production
APP_DEBUG=false
```

### Database

```bash
DB_CONNECTION=mysql
DB_HOST=…
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…
```

Each space can optionally run in its **own isolated database** — the management database stores users, teams, spaces, and billing, while space databases hold content. Space database provisioning requires credentials allowed to create databases; see [Spaces](../concepts/spaces.md#isolated-databases).

### Cache, queues, sessions

```bash
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=…
```

`QUEUE_CONNECTION=sync` works for evaluation but blocks requests on every background job — use Redis in production.

### Real-time (Reverb)

```bash
BROADCAST_DRIVER=reverb
REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=cms.example.com
REVERB_SCHEME=https
```

Any Pusher-protocol-compatible service works as an alternative to self-hosted Reverb.

### File storage

```bash
FILESYSTEM_DISK=local          # or s3 / gcs
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=…
```

Assets (uploads and generated image transformations) live on this disk. S3 or GCS is recommended for anything beyond a single-server setup.

### Search

```bash
# Default: MySQL FULLTEXT — no extra infrastructure
OPENSEARCH_HOST=…              # optional, enables the opensearch driver
OPENSEARCH_USERNAME=…
OPENSEARCH_PASSWORD=…
```

The driver is chosen **per space** in its settings; after switching, trigger a reindex from the space settings.

### Mail

Standard Laravel mail configuration (`MAIL_*`) — used for invites, notifications, and password resets.

### AI (optional)

```bash
AI_MODE=space                  # AI configuration is managed per space
AI_DEFAULT_DRIVER=openrouter
OPENROUTER_ENABLED=true
OPENROUTER_MANAGEMENT_KEY=…    # provisions per-space keys
```

AI features (generation, translation, meta tags, asset classification) are opt-in and configured per space in **Settings → AI**.

### Billing (optional)

LemonSqueezy integration for plan-based subscriptions on multi-tenant installations (`LEMONSQUEEZY_*`). Single-team installs can ignore this — spaces work without a subscription provider.

## Upgrades

```bash
git pull
composer install --no-dev
bun install && bun run build
php artisan migrate
php artisan queue:restart
```

Space databases are migrated automatically alongside the management database.

## Generating API docs

Your instance can serve its own OpenAPI reference:

```bash
php artisan docs:generate
```

writes specs per API prefix to `docs/public/specs/`. See [Generated OpenAPI specs](../api/openapi.md).
