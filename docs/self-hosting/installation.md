---
description: "Install b10cks on your own server: requirements, installation steps, production processes, and upgrades."
---

# Installation

This page covers a production-oriented setup; for local development see the [README on GitHub](https://github.com/b10cks/cms#getting-started).

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

## Installing

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

Serve `public/` with your web server of choice, then work through the [configuration reference](configuration.md).

The repository ships a `Dockerfile` based on [FrankenPHP](https://frankenphp.dev/) (Laravel Octane) with supervisor, ffmpeg, and libvips preinstalled — a good starting point for container deployments.

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

The scheduler drives recurring maintenance — usage rollups and quota alert checks, subscription sync, cleanup jobs — so it is not optional in production.

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
