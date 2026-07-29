# b10cks CMS

<div align="center">

**The opinionated headless CMS.** Model content as reusable blocks, edit it visually with your team in real time, deliver it anywhere through a fast, cached Data API. Self-host the full product (AGPL-3.0) or use [b10cks Cloud](https://app.b10cks.com).

[![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/b10cks/cms)](https://github.com/b10cks/cms/releases)
[![Docker pulls](https://img.shields.io/docker/pulls/b10cks/cms)](https://hub.docker.com/r/b10cks/cms)
[![Discord](https://img.shields.io/badge/discord-join-5865F2?logo=discord&logoColor=white)](https://discord.gg/mdcDktFFcp)

[Documentation](https://www.b10cks.com/docs) · [Website](https://www.b10cks.com) · [Cloud](https://app.b10cks.com) · [Discord](https://discord.gg/mdcDktFFcp)

</div>

![b10cks CMS](/docs/b10cks.png)

## Try it in 60 seconds

On a machine with Docker:

```bash
curl -fsSL https://get.b10cks.com | sh
```

The installer only writes into `./b10cks` — it downloads the compose stack for the newest release, generates a database password, starts the stack, and waits for the health check. It is [`scripts/install.sh`](scripts/install.sh) in this repo; read it before piping it to a shell, as you should with any such command.

The compose stack binds to `127.0.0.1` by default and speaks plain HTTP — production deployments put a TLS reverse proxy in front (the [docs](https://www.b10cks.com/docs/self-hosting/configuration.html) cover it).

Prefer to set things up by hand? The [installation guide](docs/self-hosting/installation.md) covers all three paths:

- **Docker Compose** — the official `b10cks/cms` image plus MariaDB (also mirrored to `ghcr.io/b10cks/cms`)
- **Webhost package** — a pre-built archive for traditional shared hosting: no Docker, no Composer, no shell required
- **Manual** — clone and build on your own server

## Open source, no asterisk

b10cks is licensed under the [AGPL-3.0](LICENSE). The self-hosted version is the complete product — no enterprise edition, no license keys, no feature gates. `B10CKS_EDITION=self-hosted` only disables the SaaS billing surface; everything else is identical.

[b10cks Cloud](https://app.b10cks.com) is this same codebase, hosted for you — and it funds the project.

## Features

- **Git-like content history** — every save is a commit with author and message; history branches instead of overwriting, and schema-aware diffs make review effortless.
- **Live collaboration** — Figma-style presence and real-time co-editing, down to individual blocks — comments included.
- **The Canvas** — plan and restructure whole site sections on an infinite whiteboard, then apply the plan in one click.
- **Your own Iconify registry** — brand icons served through the Iconify protocol, straight into the tooling developers already use.
- **A real query API** — 16 filter operators, sorting by your own content fields, language fallback, and revision-based caching — all in plain GET requests.
- **Settings where they belong** — per-entry child sorting and nesting rules, per-folder asset metadata requirements, per-field editor configuration. Structure without bureaucracy.

Plus everything you'd expect from a headless CMS: block-based content modeling, release management with atomic publishing, scheduled publication, a hierarchical asset library with on-the-fly image transformations (WebP/AVIF), content localization, full-text search (MySQL fulltext or OpenSearch), AI-assisted content workflows, multi-tenant spaces with isolated per-space databases, redirects, backups, and space-to-space migrations.

## Why b10cks instead of…

- **Strapi / Directus** — their open-source cores gate SSO, advanced roles, or seats behind enterprise tiers. b10cks has no gates: self-hosted is the whole product.
- **Payload** — great developer experience, but dev-first. b10cks adds the full editorial UX on top: live multiplayer editing, infinite-canvas planning, releases, comments.
- **Storyblok / Contentful** — visual block-based editing, without the SaaS lock-in. Run it yourself, keep your content in your database.

## Technical Architecture

- **Backend**: Laravel
- **Admin UI**: Vue.js with TanStack Query, Tailwind CSS, Shadcn UI, and Vue Router
- **Database**: MySQL 8.0+ or MariaDB (SQLite optionally for per-space databases via the shared profile)
- **Storage**: Local filesystem, S3, or Google Cloud Storage
- **Caching**: Redis-supported caching and queues
- **Real-time**: Laravel Reverb (Pusher-protocol compatible) for collaboration and presence
- **Search**: MySQL fulltext or OpenSearch — configurable per space
- **API**: REST endpoints for content delivery and management workflows

## Deployment Options

### Self-Hosted

Deploy b10cks on your own infrastructure — see the [self-hosting docs](https://www.b10cks.com/docs/self-hosting/):

- Docker (any VPS or cloud provider)
- AWS, Azure, Google Cloud, DigitalOcean, Linode, Vultr
- Shared hosting with PHP 8.5+ (webhost package)

### b10cks Cloud

Managed hosting for teams that do not want to operate the CMS themselves: [app.b10cks.com](https://app.b10cks.com)

## Development Setup

Contributing or hacking on b10cks itself? You need PHP 8.5+, Composer 2.5+, Bun 1.0+, and MySQL 8.0+ (or MariaDB).

```bash
git clone https://github.com/b10cks/cms.git
cd cms
bun install
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Run three processes while developing:

```bash
php artisan serve      # Laravel backend
bun run dev            # Vite dev server for the admin UI
php artisan reverb:start  # WebSockets for real-time features
```

Optional: Redis 6.0+ for caching and queues, OpenSearch for relevance-tuned search. See the [Contributing Guide](CONTRIBUTING.md) for conventions, commit format, and the PR process.

## Security

Please report vulnerabilities responsibly — see the [Security Policy](SECURITY.md).

## Community

- [Discord](https://discord.gg/mdcDktFFcp) — get help and share your knowledge
- [GitHub Discussions](https://github.com/b10cks/cms/discussions) — feature requests and technical discussions
- [Documentation](https://www.b10cks.com/docs) — guides, concepts, API reference

## License

b10cks CMS is licensed under the [GNU AGPLv3](LICENSE).

## Acknowledgements

- Taylor Otwell and contributors for Laravel
- Evan You and contributors for Vue.js
- The open source community behind the libraries used by this project

---

<div align="center">
  <p>Maintained by <a href="https://github.com/badmike">Michael Wallner</a></p>
</div>
