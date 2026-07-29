---
description: "The Data API query reference: 16 filter operators, sorting by content fields, language fallback, pagination, and search."
---

# Querying Content

The Data API is more than "fetch a page by slug" — it's a real query interface. Filter by any supported field with 16 operators, sort by system fields *or by keys inside your own content JSON*, resolve languages with fallback, search full-text, and paginate — all in one cached GET request, no query language to learn.

This page covers all of it: version selection, filters, sorting, pagination, `take`/`except`, full-text search, and the sitemap endpoint. Examples show both raw query parameters and the typed `filter` object accepted by the SDKs (`@b10cks/client` and everything built on it) — start with the SDK form; it reads better and serializes itself.

## Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/contents` | List content entries (filterable, sortable, paginated) |
| `GET /api/v1/contents/{slug}` | Single entry by full slug |
| `GET /api/v1/search?q=…` | Full-text search over published content |
| `GET /api/v1/sitemap` | Slugs + timestamps of the published tree |
| `GET /api/v1/sitemaps/{sitemap}` | A named sitemap from the space's `sitemaps` settings |

All requests authenticate with the space's access token as a `token` query parameter — the SDKs append it automatically. See [Access tokens & caching](../concepts/access-tokens.md).

## Version selection: `vid`

Every entry has up to two deliverable versions:

- `vid=published` (default) — the published version; what live traffic should see.
- `vid=draft` — the current working version; used by editor previews. Requires a token with the `preview` ability — enable "Allow draft preview" when generating the token. Tokens issued before ability enforcement keep their draft access.

```
GET /api/v1/contents/home?vid=draft
```

## Language: `language` / `language_iso` and `include_fallback`

Request a specific translation with `language=de` (alias: `language_iso`). By default this only returns entries that **have** a `de` translation. Add `include_fallback=true` to also include canonical entries without one — the i18n resolver overlays translatable fields and falls back to the canonical values, so the response is complete for the requested language:

```
GET /api/v1/contents?language=de&include_fallback=true
```

Details on the overlay model in [Internationalization](../concepts/internationalization.md).

## Filters

Filters are query parameters with an `op:value` syntax. The SDK `filter` object serializes to it automatically.

### Available filter fields

| Field | Notes |
| --- | --- |
| `id` | Content ID; supports operators (`in:`, …) |
| `parent_id` | Direct parent content ID; supports operators |
| `canonical_id` | Matches the canonical entry **and all of its translations** — combine with `language` to fetch one specific variant |
| `canonical_parent_id` | Children of a canonical parent, across all its translated parent variants |
| `content_type` | Block slug of the entry's root block, e.g. `page`, `article` |
| `language` / `language_iso` | Language ISO code |
| `include_fallback` | Boolean; see above |
| `published_at`, `updated_at`, `created_at` | ISO-8601 timestamps; support comparisons and ranges |

### Operator syntax

| Typed filter (SDK) | Wire format | Meaning |
| --- | --- | --- |
| `'value'` / `{ eq: 'v' }` | `v` / `eq:v` | Exact match |
| `{ neq: 'v' }` | `neq:v` | Not equal |
| `{ in: ['a','b'] }` | `in:a,b` | One of |
| `{ '!in': ['a','b'] }` | `!in:a,b` | None of |
| `{ like: 'v' }` | `like:v` | Contains |
| `{ '!like': 'v' }` | `!like:v` | Does not contain |
| `{ '^like': 'v' }` | `^like:v` | Starts with |
| `{ 'like$': 'v' }` | `like$:v` | Ends with |
| `{ gt / gte / lt / lte: 'v' }` | `gte:v` … | Comparison |
| `{ between: ['a','b'] }` | `a...b` | Range (dates) |
| `{ null: true }` / `{ '!null': true }` | `null:` / `!null:` | Null check |

Examples:

```
GET /api/v1/contents?content_type=article&published_at=>=2026-01-01T00:00:00Z
GET /api/v1/contents?parent_id=in:01ABC,01DEF
GET /api/v1/contents?created_at=2026-01-01...2026-01-31
```

```ts
await dataApi.getContents({
  filter: {
    content_type: 'article',
    published_at: { gte: '2026-01-01T00:00:00Z' },
    parent_id: { in: [newsId, blogId] },
  },
})
```

## Sorting

`sort` accepts a comma-separated list of fields; prefix with `-` for descending:

- Sortable columns: `position`, `published_at`, `updated_at`, `created_at`
- **JSON content fields**: `content.{field}` sorts by a top-level key of the entry's content, e.g. `sort=-content.rating` (one nesting level, `[a-zA-Z0-9_]` names)

```
GET /api/v1/contents?sort=-published_at
GET /api/v1/contents?sort=content.title,-created_at
```

If you list the children of a single `parent_id` without an explicit `sort`, and that parent has a configured child ordering (e.g. a news folder sorted by publication date), that ordering is applied automatically.

## Pagination

Standard Laravel pagination: `page` and `per_page` (default 20, max 500). Responses include a `meta` object with totals and links. The SDKs can follow pagination for you:

```ts
const all = await dataApi.getContents({ per_page: 100 }, { allPages: true })
```

## Full-text search

```
GET /api/v1/search?q=solar+panels&language=en&limit=20&offset=0
```

| Param | Default | Notes |
| --- | --- | --- |
| `q` | required | Search query |
| `language` | space default | Falls back to the space default if the language isn't enabled |
| `limit` / `offset` | 20 / 0 | Result window |

The response contains scored results plus `query`, `total`, `limit`, and `offset`. Search runs against **published** content only. The backend driver (MySQL fulltext or OpenSearch) is a space setting and doesn't change the API contract; which fields are searchable is controlled by each field's **indexable** flag in the block schema.

```ts
const results = await dataApi.search({ q: 'solar panels', language: 'en' })
```

## Sitemap

`GET /api/v1/sitemap` returns the published tree's slugs with timestamps — everything needed to build a `sitemap.xml` without paging through full content payloads:

```ts
const entries = await dataApi.getSitemap()
```

Each entry carries `id`, `name`, `full_slug`, `language_iso`, `published_at`, and a `meta` object with the normalized `robots` and `canonical` values extracted per the space's sitemap settings. Entries whose robots value contains `noindex`/`none` are excluded — and the filtering happens in the database, so pagination totals stay correct. Results are paginated (`page`, `per_page` — default 20, max 1000) and accept a `language` filter.

### Named sitemaps

A space can define **named per-type sitemaps** under its `sitemaps` settings — e.g. one sitemap for pages and a separate one for news:

```jsonc
// space settings
{ "sitemaps": [
  { "slug": "pages", "types": [{ "block": "page", "path": "meta" }] },
  { "slug": "news",  "types": [{ "block": "article", "path": "seo" }] }
] }
```

`GET /api/v1/sitemaps/{slug}` then serves each one with the same response shape, pagination, and filtering as `/sitemap`; unknown slugs return 404. Each definition is self-contained — the same block may appear in several sitemaps with different meta paths.

## `take` / `except`

On the content list, `take` limits the response to specific entries and `except` excludes entries; they are mutually exclusive — sending both returns a 422.
