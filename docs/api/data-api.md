---
description: "Endpoint catalogue of the public content delivery API: contents, blocks, data sources, redirects, search, icons."
---

# Data API

The public, read-only content delivery API. Base path: `/api/v1`. Every request authenticates with a space access token as a query parameter: `?token=…` ([details](../concepts/access-tokens.md)).

> The parameter reference for filtering, sorting, language selection, and pagination lives in [Querying content](../guides/querying-content.md). This page is the endpoint catalogue.

## Content

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/contents` | List entries. Filters: `id`, `parent_id`, `canonical_id`, `canonical_parent_id`, `content_type`, `language`, `include_fallback`, timestamp filters. `vid=published\|draft`, `sort`, `page`/`per_page` (max 500), `take`/`except`. Revision-stamped caching. |
| `GET /api/v1/contents/{slug}` | Single entry by full slug (slashes allowed). `vid`, `language`. Localized slugs resolve when `language` is set. |
| `GET /api/v1/breadcrumbs/{slug}` | Ancestor trail of an entry, root first. Addressed by full slug or by content id. `vid`, `language`, `include_self`, `ancestors`, `translations`, `include_content`, `take`/`except`. |
| `GET /api/v1/search` | Full-text search over published content. `q` (required), `language`, `limit`, `offset`. Returns scored results with totals. |
| `GET /api/v1/sitemap` | Published slugs + timestamps for sitemap generation, honoring the space's sitemap-extraction settings. |
| `GET /api/v1/sitemaps/{sitemap}` | A named sitemap defined in the space's `sitemaps` settings — e.g. separate per-type sitemaps for pages and news. Unknown slugs return 404. |

### Content entry shape

```json
{
  "id": "01JW…",
  "name": "Home",
  "slug": "home",
  "full_slug": "home",
  "language_iso": "en",
  "block": "page",
  "content": { "…fields…": "…", "nested": [{ "id": "…", "block": "teaser", "…": "…" }] },
  "position": 0,
  "published_at": "2026-07-12T09:30:00Z",
  "first_published_at": "…",
  "created_at": "…",
  "updated_at": "…"
}
```

`content` contains the field values including the full nested block tree; referenced entries, assets, and links are resolved into the payload. Per-entry cache hints (TTL, tags) configured in the entry's Config tab are delivered as response headers.

### Breadcrumbs

`GET /api/v1/breadcrumbs/{slug}` returns the trail from the tree root down to the entry, one object per level:

```json
{
  "breadcrumb": [
    {
      "id": "01JW…",
      "name": "Produkte",
      "slug": "produkte",
      "full_slug": "/startseite/produkte",
      "path": "/de/startseite/produkte",
      "block": "category",
      "depth": 1,
      "is_root": false,
      "is_current": false,
      "language_iso": "de",
      "resolved_language_iso": "de",
      "is_fallback": false,
      "is_published": true,
      "published_at": "…",
      "updated_at": "…"
    }
  ],
  "meta": {
    "language_iso": "de",
    "fallback_language_iso": "en",
    "i18n_mode": "overlay",
    "levels": 3,
    "root_id": "01JW…",
    "current_id": "01JX…"
  },
  "rv": 1753689600
}
```

Every level is resolved through its own i18n family along the requested language's fallback chain, so a level that has no translation is served from the fallback and flagged with `is_fallback` rather than dropped. `full_slug` is the stored, locale-neutral path; `path` is the delivery URL with the **requested** language's locale segment applied, even on a level that fell back.

Ancestors that are not published are omitted from the trail — an unreleased entry must not leak its name or slug through a child's breadcrumb. `depth` is the position in the content tree, so a gap in the sequence shows where a level was skipped. Pass `ancestors=all` to include them (they carry `is_published: false`); useful when structural folders are never published by design.

| Parameter | Default | Description |
| --- | --- | --- |
| `language` / `language_iso` | space default | Language every level is resolved for. Unknown values fall back to the default. |
| `vid` | `published` | `published` or `draft`. A version id is not accepted — every level is a different entry. |
| `include_self` | `true` | Include the requested entry as the last level. |
| `ancestors` | `published` | `all` includes unpublished ancestors. |
| `translations` | `false` | Add published sibling translations (`language_iso`, `name`, `full_slug`, `path`) per level. |
| `include_content` | `false` | Add the overlay-resolved `content` payload per level; honors `take`/`except`. Costs the version reads a lean trail avoids. |

The trail costs three queries regardless of tree depth: the entry lookup, one recursive query for the ancestor chain, and one for the i18n families of all levels. `include_content` adds a batched resolution pass over all levels at once. Cache headers follow the requested entry's per-entry TTL and tags, exactly like `contents/{slug}`.

## Blocks

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/blocks` | List block definitions (schema, type, tags). Filterable (e.g. `is_nestable`, `tags`, timestamps). |
| `GET /api/v1/blocks/{block}` | Single block definition by ID. |

## Data sources

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/datasources` | List data sources (only those marked API-available). |
| `GET /api/v1/datasources/{slug}/entries` | Entries of one source; supports dimension selection and pagination. Per-source cache TTL applies. |

## Redirects

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/redirects` | The redirect rule list (paginated; SDKs build a `source → {target, status_code}` map). |
| `POST /api/v1/redirects/lookup` | Resolve one source path. Body: `{ "source": "/old-path" }`. Returns the rule or `false`. |

## Space

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/spaces/me` | Metadata of the token's space — including the current content revision (`rv`) and enabled languages. |

## Icons (Iconify-compatible)

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/iconify/collections` | Available collections |
| `GET /api/v1/iconify/{prefix}.json` | Icon data in Iconify JSON |
| `GET /api/v1/iconify/{prefix}.svg` / `.css` | Sprite / CSS for a collection |
| `GET /api/v1/iconify/{prefix}/{name}.svg` / `.css` | Single icon |
| `GET /api/v1/iconify/search?query=…` | Search icons |
| `GET /api/v1/iconify/last-modified` | Cache validation |

## Caching summary

Content endpoints redirect to revision-stamped URLs and are cached long; list/lookup endpoints (blocks, data sources, redirects, search, icons) use ~60-second cache lifetimes. Full mechanics: [Access tokens & caching](../concepts/access-tokens.md#revision-based-caching).
