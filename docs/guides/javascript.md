---
description: "Use the framework-agnostic @b10cks/client to query the Data API from Node, edge runtimes, or the browser."
---

# JavaScript Guide

Use the framework-agnostic [`@b10cks/client`](https://www.npmjs.com/package/@b10cks/client) to talk to the Data API from any JavaScript or TypeScript environment — Node, edge runtimes, or the browser.

## Install

```bash
bun add @b10cks/client
```

## Create a client

```ts
import { ApiClient, createB10cksDataApi } from '@b10cks/client'

const client = new ApiClient({
  baseUrl: 'https://api.b10cks.com/api', // or your self-hosted /api URL
  token: process.env.B10CKS_TOKEN,
  version: 'published', // default version for all requests: 'published' | 'draft'
  fetchClient: fetch,
})

const dataApi = createB10cksDataApi(client)
```

## Fetch content

```ts
// Single entry by full slug
const home = await dataApi.getContent('home')

// A page of entries
const { data, meta } = await dataApi.getContents({ page: 1, per_page: 20 })

// Everything, following pagination automatically
const all = await dataApi.getContents({ vid: 'published' }, { allPages: true })

// Draft version of a single entry
const draft = await dataApi.getContent('home', { vid: 'draft' })
```

## All Data API methods

| Method | Description |
| --- | --- |
| `getContent(slug, params)` | Single content entry by full slug |
| `getContents(params, options)` | List of content entries |
| `getBlock(blockId, params)` | Single block definition by ID |
| `getBlocks(params, options)` | List of block definitions |
| `search(params)` | Full-text content search (`q`, `limit`, `offset`, `language`) |
| `getSitemap(params, options)` | Published slugs + timestamps for sitemap generation |
| `getDataSources(params, options)` | List of data sources |
| `getDataEntries(source, params, options)` | Entries of a data source (by slug) |
| `getRedirects(params, options)` | Redirect map `source → { target, status_code }` (cached) |
| `lookupRedirect(source)` | POST lookup of a single redirect |
| `getSpace(params)` | Space metadata (`spaces/me`), including the current content revision |
| `getConfig(options)` | Cached fetch of a singleton config content entry |
| `syncRevision(fallbackRv)` | Fetches the space and pins its revision for subsequent requests |

## Typed filters

`getContents`, `getBlocks`, and `getRedirects` accept a `filter` object that serializes to the API's `op:value` wire format automatically:

```ts
const articles = await dataApi.getContents({
  filter: {
    content_type: 'article',
    published_at: { gte: '2026-01-01T00:00:00Z' },
    parent_id: { in: [newsId, blogId] },
  },
  sort: ['-published_at', 'content.title'],
})
```

The full operator table (equality, `in`, `like` variants, comparisons, ranges, null checks) is documented in [Querying content](querying-content.md).

If you build requests yourself, `serializeFilter` converts a filter object to flat query params:

```ts
import { serializeFilter } from '@b10cks/client'

serializeFilter({ language: 'en', id: { in: ['a', 'b'] } })
// → { language: 'en', id: 'in:a,b' }
```

## Revisions and caching

Data API responses are cached aggressively (server-side and CDN). Cache identity includes the space's **content revision** (`rv`): whenever content is published, the revision changes and clients naturally get fresh responses.

- `getSpace()` returns the current revision; `syncRevision()` fetches and pins it on the client so subsequent requests hit the freshest cache generation.
- Pass `rv: Date.now()` in the `ApiClient` options to bypass caches entirely (useful in previews and tests, not for production traffic).

The client also keeps small in-memory caches for redirects (`getRedirects`) and config content (`getConfig`); pass `forceRefresh` to bypass them.

## Error handling

Failed requests reject with an error carrying the HTTP status and response body. A `lookupRedirect()` that finds nothing resolves to `false` rather than rejecting.

```ts
try {
  await dataApi.getContent('missing-page')
} catch (e) {
  // e.status === 404
}
```

## Typed content

`getContent`/`getContents` accept a type parameter for the entry's field values. Generate interfaces for all your block schemas with the [CLI](cli.md#generate-types), then:

```ts
import type { B10cksArticle } from './b10cks/types'

const article = await dataApi.getContent<B10cksArticle>('blog/hello-world')
article.content.headline // typed
```

## Beyond the Data API

- **Writing content, publishing, managing spaces** — use the typed [`@b10cks/mgmt-client`](management-client.md) against the Management API.
- **Scripting from the terminal** — the [`@b10cks/cli`](cli.md) covers common workflows (spaces, blocks, contents, type generation) without writing code.
