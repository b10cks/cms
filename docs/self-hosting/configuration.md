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

Uploaded assets live on this disk. S3 or GCS is recommended for anything beyond a single-server setup. Image transformations are **not** written back to storage — they are computed per request and cached at the CDN, so see [Media delivery](#media-delivery) below before putting an origin on the public internet without one.

## Media delivery

Defaults for the [image service](../concepts/image-service.md); all optional.

```bash
IMAGE_DRIVER=vips              # or imagick
IMAGE_BASE_URL=https://…/ilum  # public origin the delivery URLs are built from
IMAGE_DEFAULT_FORMAT=webp      # output format when a transformation omits one
IMAGE_MAX_WIDTH=5000           # requested dimensions are clamped to these
IMAGE_MAX_HEIGHT=5000
IMAGE_MAX_SOURCE_PIXELS=100000000  # decompression-bomb guard; 0 disables it
IMAGE_WEBP_QUALITY=85          # per-format encoder quality (also AVIF/JPG/PNG)
```

`vips` is considerably faster and leaner than `imagick`; use `imagick` only where the libvips extension is unavailable.

Caching and streaming:

```bash
IMAGE_CACHE_DURATION=31536000      # max-age on delivery responses
IMAGE_CACHE_IMMUTABLE=true         # mark transformed images immutable
IMAGE_CACHE_PASSTHROUGH_IMMUTABLE=false  # untransformed files stay revalidatable
IMAGE_CACHE_POSTER_DURATION=3600   # TTL for poster URLs without a `v` pin
IMAGE_STREAM_CHUNK_SIZE=1048576    # bytes per chunk when streaming media
IMAGE_STREAM_MAX_SECONDS=900       # ceiling on a single transfer (0 = no limit)
IMAGE_RATE_LIMIT=600               # delivery requests per minute per IP
```

Leave `IMAGE_CACHE_PASSTHROUGH_IMMUTABLE` off unless you are certain no file is ever served from a reused path — it removes the revalidation escape hatch for a year.

The delivery routes are unauthenticated and each in-flight transfer occupies a PHP worker for its duration, so both `IMAGE_RATE_LIMIT` and `IMAGE_STREAM_MAX_SECONDS` are there to stop slow or abusive clients from exhausting the pool. Raise `IMAGE_STREAM_MAX_SECONDS` if you serve very large files to slow connections; raise `IMAGE_RATE_LIMIT` if a single content-heavy page legitimately pulls more than 600 assets per minute per visitor. Behind a CDN, most requests never reach the origin and the defaults are ample.

Video and other non-image assets are streamed with byte-range support, which is what makes seeking work and is a hard requirement for playback in Safari. If you terminate TLS or proxy in front of the origin, make sure the proxy forwards `Range` and does not buffer whole responses — nginx needs `proxy_buffering off` (or a large `proxy_max_temp_file_size`) on the delivery location for large media.

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
