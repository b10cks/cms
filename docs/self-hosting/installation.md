---
description: "Install b10cks on your own server: Docker, webhost package, or manual setup — requirements, installation steps, production processes, and upgrades."
---

# Installation

There are three ways to run b10cks yourself, from most to least automated:

1. **Docker Compose** — the official `b10cks/cms` image plus MariaDB, one command.
2. **Webhost package** — a pre-built archive for traditional shared hosting: no Docker, no Composer, no shell required.
3. **Manual** — clone and build on your own server.

## Quickstart

```bash
curl -fsSL https://get.b10cks.com | sh
```

The installer checks for Docker, downloads the compose stack for the newest release into `./b10cks`, generates a database password, starts the stack, and waits until the app answers its health check. It is [`scripts/install.sh`](https://github.com/b10cks/cms/blob/main/scripts/install.sh) in the repo — read it before piping it to a shell, as you should with any such command.

Three environment variables change what it does:

| Variable | Default | Purpose |
| --- | --- | --- |
| `B10CKS_DIR` | `./b10cks` | Where the compose file and `.env` are written |
| `B10CKS_VERSION` | newest release | Install a specific tag, e.g. `v2026.7.29-5acd0668` |
| `B10CKS_PORT` | `8000` | Host port for the app |

```bash
curl -fsSL https://get.b10cks.com | B10CKS_PORT=9000 sh
```

The result is an ordinary Compose installation — the sections below describe the same stack, set up by hand.

All three end with the same installer, `php artisan b10cks:setup`, which prepares directories, generates `APP_KEY` if missing, migrates, seeds, sets up the plan, and records the install.

Set `B10CKS_EDITION=self-hosted` in every self-hosted deployment — it disables the SaaS billing surface, seeds a single unlimited plan, and keeps b10cks.com branding out of outgoing mail. See the [configuration reference](configuration.md).

## Docker Compose

What the quickstart above automates, step by step:

```bash
curl -LO https://raw.githubusercontent.com/b10cks/cms/main/docker-compose.yml
curl -Lo .env https://raw.githubusercontent.com/b10cks/cms/main/.env.docker.example
# edit .env (APP_URL, DB_ROOT_PASSWORD, mail settings)
docker compose up -d
```

Set `B10CKS_IMAGE_TAG` in `.env` to a concrete [release tag](https://github.com/b10cks/cms/releases) rather than keeping the `latest` default — that is what the quickstart installer does, and it keeps a reinstall reproducible and the compose file in step with the image it runs.

On first boot the container generates an `APP_KEY` (persisted on the storage volume) and runs `b10cks:setup` automatically (`B10CKS_AUTO_SETUP=true`). Optional services are behind compose profiles:

```bash
docker compose --profile redis --profile opensearch --profile reverb up -d
```

Realtime collaboration needs the `reverb` profile plus `BROADCAST_DRIVER=reverb` and `REVERB_APP_KEY`/`REVERB_APP_SECRET`; without it the app runs fine, just without live presence.

Images are published for every release tag to [Docker Hub](https://hub.docker.com/r/b10cks/cms) and mirrored to [GHCR](https://github.com/b10cks/cms/pkgs/container/cms) as `ghcr.io/b10cks/cms`. Both are published as multi-arch manifests for `linux/amd64` and `linux/arm64`, so Apple Silicon and arm64 VPS hosts pull a native image — Docker picks the right one automatically. Other architectures are not supported; the installer refuses them up front rather than failing later. The compose file points at Docker Hub; if you are behind a shared IP and hit its unauthenticated pull limit, keep `B10CKS_IMAGE_TAG` as-is and change the `image:` name in `docker-compose.yml` to `ghcr.io/b10cks/cms`.

## Webhost package (shared hosting)

Download `b10cks-cms-webhost-<version>.tar.gz` (or `.zip`) from the [GitHub releases](https://github.com/b10cks/cms/releases) — it ships `vendor/` and the built admin UI, so nothing needs to be compiled on the host.

1. Extract the archive and point your domain's web root at the `public/` directory.
2. Copy `.env.example` to `.env` and fill in `APP_URL` and the database credentials your hoster gave you.
3. Open `https://your-domain.example/setup` in the browser. The installer runs once and disarms itself.
4. Add one cron job — it drives the scheduler *and* drains the queue:

```
* * * * * php /path/to/b10cks/artisan schedule:run >> /dev/null 2>&1
```

The package defaults to the **shared** install profile: spaces live in your single database behind per-space table prefixes, so no `CREATE DATABASE` privileges are needed. If your host provides `pdo_sqlite`, you can set `B10CKS_SPACE_DB_DRIVER=sqlite` instead to keep each space in its own file under `storage/app/spaces/`.

Long-running work (large asset packages, big imports) is limited by your host's PHP execution limits — jobs run in sub-minute bursts from cron. For heavy use, prefer the Docker or manual setup with a real queue worker.

## Manual

```bash
git clone https://github.com/b10cks/cms.git
cd cms
composer install --no-dev
bun install
bun run build
cp .env.example .env
php artisan b10cks:setup
```

Serve `public/` with your web server of choice, then work through the [configuration reference](configuration.md).

`b10cks:setup --profile=standard` (the default) creates one database per space and needs administrative database credentials in `.env`; `--profile=shared` works with a single database and restricted credentials.

### Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.5+ (with `bcmath`, `exif`, `gd`/`imagick`, `intl`, `pdo_mysql`, `pcntl` extensions) |
| Database | MySQL 8.0+ / MariaDB (SQLite for space databases via the shared profile) |
| Node / Bun | Node 20+ and Bun 1.0+ (build-time only, for the admin UI) |
| Composer | 2.5+ |
| Redis | Optional — caching and queues at scale (`database` driver works fine) |
| ffmpeg / libvips | Optional — video previews and fast image processing |
| OpenSearch | Optional — relevance-tuned full-text search at scale (MySQL driver is the default) |

## Processes

A full production deployment runs three long-lived processes:

| Process | Command | Purpose |
| --- | --- | --- |
| App server | `php artisan octane:frankenphp` (or classic PHP-FPM + nginx) | HTTP: admin UI, Data API, Management API |
| Queue worker | `php artisan queue:work` | Background jobs: publishing, backups, imports/exports, asset processing, AI |
| WebSockets | `php artisan reverb:start` | Real-time collaboration, presence, notifications (optional) |

Plus the Laravel scheduler in cron:

```
* * * * * php /path/to/cms/artisan schedule:run >> /dev/null 2>&1
```

On the shared profile the scheduler also drains the queue, so the single cron line is genuinely all a webhost needs.

## Upgrades

### Docker Compose

Set `B10CKS_IMAGE_TAG` in your `.env` to the new [release tag](https://github.com/b10cks/cms/releases), then:

```bash
docker compose pull
docker compose up -d
```

Migrations apply themselves. On boot the app container compares the image's version against the one recorded on the storage volume and, when they differ, runs `b10cks:upgrade` before serving traffic — so an ordinary restart costs nothing while an actual upgrade is never forgotten. The restarted containers pick up new queue workers automatically, so no separate `queue:restart` is needed.

To watch it happen, or to confirm it ran:

```bash
docker compose logs app | grep -i upgrad
```

### Manual

```bash
git pull
composer install --no-dev
bun install && bun run build
php artisan b10cks:upgrade
php artisan queue:restart
```

### Webhost package

For the webhost package: extract the new archive over the old tree (keep your `.env` and `storage/`), then run `php artisan b10cks:upgrade`. Re-arming the HTTP installer is **not** needed — migrations are the only upgrade step.

::: warning Migrate space databases too, not just the management one
`php artisan migrate` alone is **not** enough on any of these paths. It updates the management database, while each space carries its own schema — one database per space on the standard profile, prefixed tables or a SQLite file per space on the shared profile. `b10cks:upgrade` runs both, applying space migrations only where they are actually pending. If you have previously upgraded with `migrate` alone, run `php artisan b10cks:upgrade --force` once to catch the spaces up.
:::

## Generating API docs

Your instance can serve its own OpenAPI reference:

```bash
php artisan docs:generate
```

writes specs per API prefix to `docs/public/specs/`. See [Generated OpenAPI specs](../api/openapi.md).
