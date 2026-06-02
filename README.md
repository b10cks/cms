# b10cks CMS

<div align="center">

An opinionated headless CMS built with Laravel and Vue.js.

[![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-orange.svg)](https://laravel.com/)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.x-brightgreen.svg)](https://vuejs.org/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-blue.svg)](https://www.typescriptlang.org/)
[![MadeWithVueJs.com shield](https://madewithvuejs.com/storage/repo-shields/6367-shield.svg)](https://madewithvuejs.com/p/b10cks/shield-link)

</div>

![b10cks CMS](/docs/b10cks.png)

## Overview

b10cks is an API-first headless CMS for teams that model content as reusable blocks and deliver it through APIs. The repository contains the self-hosted CMS application, including the Laravel backend, Vue-based admin UI, content editing tools, asset handling, search integrations, and multi-tenant space management.

Hosted SaaS options are available separately:

- Website: [www.b10cks.com](https://www.b10cks.com)
- Hosted app: [app.b10cks.com](https://app.b10cks.com)

## Features

### Content Editing

- **Block-based content modeling**: Define reusable, versioned blocks and compose them into content structures.
- **Canvas editor**: Visual tree editor with drag-and-drop, pan and zoom, keyboard support, and undo/redo history.
- **Content versioning**: Version history with selective publishing and scheduled publication.
- **Release management**: Group multiple content versions into a release and publish them atomically.
- **Comments and reviews**: Threaded comments on content items with resolution tracking and reactions.

### Search

- **Full-text search**: MySQL fulltext indexes by default, with OpenSearch support for larger or relevance-tuned deployments.
- **Localization-aware filtering**: Multi-language filtering and relevance scoring.

### Collaboration

- **Real-time collaboration**: Presence tracking per space, content item, and canvas, including cursor positions.
- **AI assistance**: Multi-model integration for content generation, translation, meta tag generation, and canvas structuring, with streaming responses.

### Assets & Media

- **Asset management**: Hierarchical asset library with folders, tags, and bulk import/export.
- **Image transformations**: On-the-fly resize, crop, and smart-fit transformations via the built-in Ilum service, with WebP, AVIF, JPG, and PNG output.

### Infrastructure

- **Internationalization**: Content localization across multiple languages.
- **REST API**: API endpoints for integrating content into frontend applications and other services.
- **Multi-tenant spaces**: Isolated per-space databases with team and role management.
- **Data sources**: Structured dynamic data management with dimension support.
- **Redirects**: URL redirect management with hit tracking.
- **Backups and migrations**: Space backups and direct DB-to-DB space migration with configurable conflict resolution.
- **Subscription billing**: LemonSqueezy integration for plan-based subscriptions.

## Technical Architecture

b10cks CMS uses the following main components:

- **Backend**: Laravel
- **Admin UI**: Vue.js-based interface with TanStack Query, Tailwind CSS, Shadcn UI, and Vue Router
- **Database**: PostgreSQL, MySQL, MariaDB, or SQLite
- **Storage**: Local filesystem, S3, or Google Cloud Storage
- **Caching**: Redis-supported caching and queues
- **Real-time updates**: Laravel Echo + Pusher for real-time updates and collaboration
- **Search**: MySQL fulltext or OpenSearch — configurable per space
- **API**: REST endpoints for content delivery and management workflows

## Getting Started

### Prerequisites

- **Node.js**: Install Node.js version 20 or higher.
- **Bun**: Install Bun version 1.0 or higher.
- **PHP**: Install PHP version 8.4 or higher.
- **Composer**: Install Composer version 2.5 or higher.
- **MySQL**: Install MySQL version 8.0 or higher.

### Installation

```bash
git clone https://github.com/b10cks/cms.git
cd cms
bun install
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Running the Application Locally

Start the Laravel backend:

```bash
php artisan serve
```

In a second terminal, start the Vite development server for the Vue admin UI:

```bash
bun run dev
```

In a third terminal, start Laravel Reverb for WebSocket-based real-time features:

```bash
php artisan reverb:start
```

All processes need to keep running while developing locally. The Laravel server handles the backend, `bun run dev` serves the frontend assets used by the admin UI, and Reverb powers real-time updates and collaboration.

#### Optional

- **Redis**: Install Redis version 6.0 or higher for caching and queues.
- **OpenSearch**: Install OpenSearch for advanced full-text search capabilities.

### Search Configuration

b10cks supports two search drivers. Set the driver per space in the admin UI or via the API:

- **`mysql`** (default): Uses MySQL `FULLTEXT` indexes with natural language mode — no extra infrastructure needed.
- **`opensearch`**: Connects to an OpenSearch cluster for scalable, relevance-tuned search. Configure via `OPENSEARCH_HOST`, `OPENSEARCH_USERNAME`, `OPENSEARCH_PASSWORD` in your `.env`.

After switching drivers, trigger a reindex from the space settings.

## Deployment Options

### Self-Hosted

Deploy b10cks on your own infrastructure:

- AWS, Azure, Google Cloud
- Docker
- DigitalOcean, Linode, Vultr
- Any VPS provider
- Shared hosting with PHP 8.4+

### b10cks CMS Cloud

Managed SaaS hosting is available for teams that do not want to operate the CMS themselves.

- Product website: [www.b10cks.com](https://www.b10cks.com)
- Hosted application: [app.b10cks.com](https://app.b10cks.com)

## Security Vulnerabilities

If you discover a security vulnerability within this project, please send an e-mail to [security@b10cks.com](mailto:security@b10cks.com).
All security vulnerabilities will be promptly addressed.

## Contributing

See the [Contributing Guide](CONTRIBUTING.md) before opening a pull request.

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/example`
3. Commit your changes: `git commit -m 'Add example feature'`
4. Push to the branch: `git push origin feature/example`
5. Open a Pull Request

## Community Support

- [Discord](https://discord.gg/zAz6sBDpHT) - Get help and share your knowledge
- [GitHub Discussions](https://github.com/b10cks/cms/discussions) - Feature requests and technical discussions

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
