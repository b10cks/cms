---
description: "The .env configuration reference for self-hosted b10cks: application, database, cache, real-time, storage, search, mail, and AI."
---

# Configuration

All configuration happens through the standard Laravel `.env` file. This page covers the keys that matter for a production instance; billing has [its own page](plans-and-pricing.md).

## Edition

```bash
B10CKS_EDITION=self-hosted     # saas (default) | self-hosted
```

`self-hosted` turns off the SaaS billing surface: the subscription UI and LemonSqueezy webhooks disappear, billing/metering cron jobs stop, `b10cks:setup` seeds a single unlimited plan, and outgoing mail drops the b10cks.com footer. Individual features can be overridden with `B10CKS_FEATURE_BILLING` / `B10CKS_FEATURE_AI`.

```bash
# Optional mail-footer imprint (rendered when company is set)
B10CKS_IMPRINT_COMPANY="Example GmbH"
B10CKS_IMPRINT_ADDRESS="Musterstraße 1, 12345 Berlin"
B10CKS_IMPRINT_NOTICE="You receive this email because of your account at cms.example.com."
```

## Installer

```bash
B10CKS_INSTALL_PROFILE=standard   # standard | shared
B10CKS_SPACE_DB_DRIVER=mysql      # shared profile: mysql (prefixes) | sqlite (file per space)
B10CKS_HTTP_SETUP_ENABLED=false   # enables GET /setup (or create storage/app/setup/http-enabled)
B10CKS_AUTO_SETUP=true            # Docker entrypoint: run b10cks:setup on first boot
```

`php artisan b10cks:setup` is idempotent per install — it records its state in `storage/app/setup/install-state.json`.

## Registration

A self-hosted instance accepts open sign-ups only until the first account exists; that account becomes the owner, and everyone after it joins by invitation. Because a fresh install on a public address is claimable by whoever reaches it first, create the first account immediately after installing.

Once an account is seen, the closure is latched into `storage/app/setup/registration-closed` rather than re-derived from the database on every request — so a database outage cannot reopen the instance, and neither can deleting the accounts. The latch lives on the storage volume and survives upgrades.

To reopen sign-ups deliberately — for example to re-create the owner after losing access — either delete that file or set:

```bash
B10CKS_ALLOW_REGISTRATION=true
```

The environment variable wins over everything, in both directions: set it to `false` to keep registration closed even on a brand-new install.

## Application

```bash
APP_URL=https://cms.example.com
APP_DOMAIN=cms.example.com
SANCTUM_STATEFUL_DOMAINS=cms.example.com
APP_ENV=production
APP_DEBUG=false
```

The scheme in `APP_URL` decides the scheme every generated URL gets — assets, redirects, signed links, mail. Put `https://` there as soon as anything terminates TLS in front of the app, even when the app itself is reached over plain HTTP inside the network. Leave it `http://` for a local stack that speaks plain HTTP end to end, which is what the Docker default does.

## Sign-in

```bash
AUTH_2FA_GRACE_PERIOD=30       # minutes a verified 2FA session stays verified

# Optional social login (both providers are off unless configured)
GOOGLE_CLIENT_ID=…
GOOGLE_CLIENT_SECRET=…
GOOGLE_REDIRECT_URI=https://cms.example.com/auth/v1/social/google/callback
GITHUB_CLIENT_ID=…
GITHUB_CLIENT_SECRET=…
GITHUB_REDIRECT_URI=https://cms.example.com/auth/v1/social/github/callback
```

SAML single sign-on needs no `.env` entries — it is configured per team in the app ([Teams → Single sign-on](../ui/account.md#single-sign-on-saml)).

## Management API access

```bash
# Origins allowed to make credentialed cross-origin calls to /mgmt/* and
# /auth/v1/*. Comma-separated; defaults to APP_FRONTEND_URL and APP_URL.
# Never '*' — these routes carry the admin's session cookie.
MGMT_ALLOWED_ORIGINS=https://cms.example.com

MGMT_RATE_LIMIT=1000           # management API requests per minute
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

Each space can optionally run in its **own isolated database** — the management database stores users, teams, spaces, and billing, while space databases hold content. With the **standard** install profile, provisioning requires credentials allowed to create databases and users; see [Spaces](../concepts/spaces.md#isolation-model).

With the **shared** install profile no administrative privileges are needed: spaces live in the main database behind a per-space table prefix (`sp<hash>_…`), or — with `B10CKS_SPACE_DB_DRIVER=sqlite` — in one SQLite file per space under `storage/app/spaces/`.

If your **main** database is SQLite, each space always gets its own file regardless of `B10CKS_SPACE_DB_DRIVER`: table prefixes inside the main SQLite file would make a space's database indistinguishable from the installation's own, so deleting one space would remove everything and a space backup would include every other space.

## Cache, queues, sessions

```bash
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=…
```

`QUEUE_CONNECTION=sync` works for evaluation but blocks requests on every background job. `database` is a solid zero-infrastructure default (it is what the webhost package uses); Redis is recommended at scale. On the shared install profile the scheduler drains the queue from cron, so no long-running worker is required.

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

Realtime is optional: with `BROADCAST_DRIVER=null` (or no Reverb key) the admin UI simply runs without live presence and collaboration — nothing breaks and no websocket connections are attempted.

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
                               # (defaults to APP_URL/ilum on self-hosted)
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

Video posters and duration/dimension probing shell out to ffmpeg. Point these at your binaries if they are not on the default path:

```bash
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
```

Without a working ffmpeg, video uploads still work — they just get no generated poster frames, and editors have to upload a [custom poster](../ui/assets.md#posters-and-thumbnails).

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

## Transfers (packages & backups)

```bash
TRANSFERS_DISK_DRIVER=local    # or s3 (default)
```

Asset download packages and backups are written to the transfers disk. `local` keeps them under `storage/app/transfers` and serves downloads through short-lived signed application URLs — no S3 required.

Defaults for [asset packages and public shares](../concepts/assets.md#sharing--downloads); all optional:

```bash
ASSET_PACKAGE_EXPIRY_DAYS=7            # how long a built zip stays before it is pruned
ASSET_PACKAGE_URL_TTL_MINUTES=15       # lifetime of a single issued download URL
ASSET_PACKAGE_MAX_SIZE_MB=10240        # ceiling on a package's summed source size (0 = unlimited)
ASSET_PACKAGE_FAILED_BUILD_COOLDOWN_MINUTES=10  # wait before retrying a failed build
ASSET_SHARE_ACCESS_TOKEN_TTL_MINUTES=60 # lifetime of the token issued by unlocking a share
ASSET_SHARE_EVENT_RETENTION_DAYS=365    # how long share access events are kept
```

A package build copies the source files locally and zips them alongside, so local disk needs roughly twice `ASSET_PACKAGE_MAX_SIZE_MB` while a build runs.

### Metered downloads through CloudFront (optional)

Large share downloads can be served by CloudFront instead of the origin, with signed URLs and download metering from the distribution's access logs:

```bash
CLOUDFRONT_DOWNLOAD_BASE_URL=https://dl.example.com   # /dl/* behavior fronting the transfers bucket
CLOUDFRONT_SIGNING_KEY_PAIR_ID=…
CLOUDFRONT_SIGNING_PRIVATE_KEY=…       # PEM contents or a path to a .pem file
CLOUDFRONT_LOG_BUCKET=…                # access logs ingested for download counts
CLOUDFRONT_LOG_PREFIX=
CLOUDFRONT_DISTRIBUTION_ID=…           # used for cache invalidation
USAGE_HOURLY_RETENTION_DAYS=180        # how long hourly usage rows are kept
```

Without these, downloads fall back to presigned S3 URLs (or signed application URLs on a local transfers disk).

## Search

```bash
# Default: MySQL FULLTEXT — no extra infrastructure
OPENSEARCH_HOST=…              # optional, enables the opensearch driver
OPENSEARCH_USERNAME=…
OPENSEARCH_PASSWORD=…
```

The driver is chosen **per space** through the space's `search_driver` setting (Management API or the MCP/CLI tooling; there is no switch in the admin UI). After switching, reindex the space:

```bash
php artisan search:reindex {space_id}
```

The Management API exposes the same operation as `POST /mgmt/v1/spaces/{space}/search/reindex`.

## Mail

Standard Laravel mail configuration (`MAIL_*`) — used for invites, notifications, and password resets.

```bash
NOTIFICATIONS_MAIL_DELAY_MINUTES=5
```

In-app notifications are delivered instantly. The email fallback is queued with this delay and dropped if the recipient has already read the notification in the app by the time the job runs — so email stays for what actually needs it.

## AI (optional)

```bash
AI_MODE=single                 # self-hosted default: one platform key for all spaces
OPENROUTER_API_KEY=…           # empty disables AI features

# SaaS-style per-space keys instead (needs an OpenRouter provisioning key):
#AI_MODE=space
#OPENROUTER_MANAGEMENT_KEY=…
```

```bash
# Provider selection — OpenRouter is the default and the only one with
# per-space key provisioning. The others are single-key drivers.
AI_DEFAULT_DRIVER=openrouter   # openrouter | openai | bedrock
OPENROUTER_ENABLED=true
#OPENAI_ENABLED=true
#OPENAI_API_KEY=…
#BEDROCK_ENABLED=true          # uses the AWS_* credentials
```

AI features (generation, translation, meta tags, asset classification) are opt-in and configured per space in **Settings → AI**. In `single` mode there is no per-space spend metering — usage counts against your one key.

## Data API usage tracking (optional)

```bash
TRACK_TOKEN_USAGE=false        # record per-token request counts and timings
```

Off by default: every delivery request writes an execution row and updates aggregates, which is real write load on a busy instance. Turn it on when you want per-token usage statistics in **Settings → Access tokens**.

## Billing (optional)

Plan-based subscriptions via LemonSqueezy for multi-tenant installations. Single-team installs can ignore this — spaces work without a subscription provider. See [Plans & pricing](plans-and-pricing.md).
