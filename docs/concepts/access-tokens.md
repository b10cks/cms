---
description: "How the Data API authenticates and how revision-based caching keeps delivery fast and fresh."
---

# Access Tokens & Caching

How the Data API authenticates, and how its revision-based caching keeps delivery fast **and** fresh.

## Access tokens

Data API requests authenticate with a space-scoped token, passed as a query parameter:

```
GET /api/v1/contents/home?token=YOUR_TOKEN
```

Create and revoke tokens in **Settings → Access tokens**. Tokens:

- belong to exactly one space (the token *is* the space selector — no space ID in the URL)
- carry **abilities** in `resource:action` form (e.g. `contents:read`, or `*:read` for read-everything); delivery tokens are read-only by nature
- can expire, and record usage statistics (request counts, execution stats) you can inspect in the space's usage page
- only work while the space is `live`

Use one token per environment (production, staging, preview) so each can be revoked independently. A leaked delivery token exposes *read* access to the space's published and draft content — rotate it in settings.

> **Management API is different.** The admin/management API (`/mgmt/v1/…`) authenticates with per-user personal access tokens as a `Bearer` header, not with space delivery tokens. See [API overview](../api/overview.md).

## Revision-based caching

Data API responses are aggressively cacheable because every URL is pinned to a **content revision** (`rv`):

1. Content-serving requests without `rv` are **301-redirected** to the same URL with `rv=<timestamp of the space's last content change>` appended. The redirect itself is cached only briefly (`max-age=60`).
2. The `rv`-stamped response is cached long (`max-age=3600, s-maxage=86400` by default) — by browsers, CDNs, and the server.
3. Publishing content changes the space's revision. New requests redirect to new `rv` URLs — old cache entries become irrelevant without any purging.

In practice:

- **Do nothing special** and you get correct freshness: the cheap redirect re-validates every minute; heavy payloads are served from cache.
- The SDKs can **pin the revision once per session** (`syncRevision()` / the `rv` client option) to skip the redirect hop for subsequent requests.
- To **bypass caches** (previews, tests), pass a unique `rv`, e.g. `rv=Date.now()`.

Endpoints that aren't content-revision-bound (blocks, data sources, redirects, search, icons) are served with shorter cache lifetimes (~60s) instead of the redirect flow.

Responses also include `cache-tag` headers, enabling targeted CDN invalidation on setups that support it.

## HTTPS, CORS, CDN

- The Data API is CORS-open for GET requests — browser apps can call it directly.
- All endpoints are plain HTTP GET (plus the redirect-lookup POST), which makes them trivially CDN-cacheable; putting a CDN in front of a self-hosted instance requires no special configuration beyond honoring `Cache-Control`.
