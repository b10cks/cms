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

## Reverse proxy & trusted hosts

Required when the instance runs behind a load balancer, CDN, or any reverse proxy.

```bash
# Proxies whose X-Forwarded-* headers may be believed, as a comma-separated
# list of IPs/CIDR ranges. This decides what the app considers the client IP,
# which every per-IP rate limit and the audit log depend on. Leave it empty
# only when clients connect directly.
TRUSTED_PROXIES=172.16.0.0/12

# Host headers accepted in addition to APP_URL and its subdomains, as a
# comma-separated list of hostnames or regular expressions. Needed for
# additional domains and for health checks that hit the instance by IP.
TRUSTED_HOSTS=^api\.example\.com$,^10\.0\.\d+\.\d+$
```

Leaving `TRUSTED_PROXIES` empty behind a balancer makes every request appear to come from the balancer, collapsing all per-IP throttles into one shared bucket. Setting it to `*` lets anyone who can reach the app directly forge `X-Forwarded-For` — use it only when the app is genuinely unreachable except through the proxy. If a CDN sits in front of the balancer, include the CDN's origin-facing ranges as well, or client IPs will resolve to the CDN edge.

Requests with a Host header outside `APP_URL`, its subdomains, and `TRUSTED_HOSTS` are rejected with a 400 — remember to list the host your load balancer health checks use.

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

## Delivery performance (optional)

```bash
# Origin micro-cache TTL in seconds for the heavy delivery endpoints
# (content listing/detail, search, sitemap). Collapses the CDN-miss stampede
# after a publish: each unique URL is computed once per TTL window. Keys
# include the token and space revision, so entries are isolated per space and
# invalidate on publish. Disabled by default.
DATA_API_MICRO_CACHE_TTL=5

# Requests per minute per IP against the public image transformation
# endpoint. Each distinct transformation forces a fresh decode at the origin.
IMAGE_RATE_LIMIT=600
```

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
