# b10cks - An opinionated headless CMS

<div align="center">

<h3>Build the future of content, block by block.</h3>

An opinionated headless CMS based on Laravel and Vue.js

[![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-orange.svg)](https://laravel.com/)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.x-brightgreen.svg)](https://vuejs.org/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-blue.svg)](https://www.typescriptlang.org/)

</div>

## 🚀 Why Choose b10cks?

b10cks is a modern API-first headless CMS built on Laravel and Vue.js that gives developers the freedom to create without constraints while providing content editors with powerful visual tools.

- **Content, your way**: Structure your content with a flexible block-based architecture that adapts to your needs, not the other way around.
- **API-first, future-ready**: Rest easy knowing your content can be delivered to any platform, now or in the future.
- **Developer experience first**: We obsess over the developer experience so you can focus on building, not fighting with your CMS.

## Features

- **🧩 Block-Based Content Modeling**: Define and compose content structures with reusable blocks
- **🌇 Asset Management**: Comprehensive asset library with folders, tags, and metadata
- **🌍 Internationalization**: Built-in support for content localization
- **🔌 API-First**: Powerful REST API for seamless integration with any frontend
- **🧑🏻‍💻 Developer Tooling**: Extensive SDK and tools for a smooth development experience
- **🔍 Content Preview**: Real-time visual preview with live editing capabilities
- **👥 Team Collaboration**: Role-based access control for efficient teamwork
- **📊 Data Sources**: Structured data management with dimension support
- **🔄 Versioning**: Track content history with comprehensive versioning

## Technical Architecture

b10cks CMS is built with a modern stack optimized for performance and developer experience:

- **Backend**: Powered by Laravel, offering robust reliability and security
- **Admin UI**: Vue.js-based interface with TanStack Query, Tailwind CSS, Shadcn UI, and Vue Router
- **Database**: Flexible with support for PostgreSQL, MySQL, MariaDB, and SQLite
- **Storage**: Local and cloud storage options for assets, including S3 and Google Cloud Storage
- **Caching**: Intelligent multi-level caching strategy with Redis support
- **Real-time Updates**: Laravel Echo + Pusher for real-time updates and collaboration
- **API**: RESTful endpoints with comprehensive documentation and SDKs

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
php artisan serve
```

#### Optional

- **Redis**: Install Redis version 6.0 or higher.

## Deployment Options

### Self-Hosted

Deploy b10cks on your infrastructure, your way:

- AWS, Azure, Google Cloud
- Docker
- DigitalOcean, Linode, Vultr
- Any VPS provider
- Shared hosting with PHP 8.4+

### b10cks CMS Cloud

Save time with our managed hosted SaaS offerings:

- Automatic scaling and updates
- Global CDN distribution
- 99.9% uptime guarantee

## Security Vulnerabilities

If you discover a security vulnerability within this project, please send an e-mail to [security@b10cks.com](mailto:security@b10cks.com).
All security vulnerabilities will be promptly addressed.

## Contributing

We love contributions! Check out our [Contributing Guide](CONTRIBUTING.md) to get started.

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes: `git commit -m 'Add some amazing feature'`
4. Push to the branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

## 💬 Community Support

Join our growing community:

- [Discord](https://discord.gg/UT6GrvhvBx) - Get help and share your knowledge
- [GitHub Discussions](https://github.com/b10cks/cms/discussions) - Feature requests and technical discussions

## License

b10cks CMS is 100% open-source software licensed under the [GNU AGPLv3](LICENSE).

## Acknowledgements

- Taylor Otwell and contributors for their incredible framework Laravel
- Evan You and contributors for their incredible framework Vue.js
- The open source community for the numerous other libraries that made this possible

---

<div align="center">
  <p>Built with 🖤️ by <a href="https://github.com/badmike">Michael Wallner</a></p>
</div>
