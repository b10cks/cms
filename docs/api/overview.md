---
description: "The three b10cks APIs — Data, Management, Auth — base URLs and authentication."
---

# API Overview

b10cks exposes three HTTP APIs. All of them speak JSON and live under your instance's base URL (`https://api.b10cks.com` for b10cks Cloud, your own host when self-hosting).

| API | Base path | Audience | Auth |
| --- | --- | --- | --- |
| **Data API** | `/api/v1` | Frontends and delivery integrations | Space access token, `?token=` query parameter |
| **Management API** | `/mgmt/v1` | The admin UI, scripts, CI, integrations | Personal access token, `Authorization: Bearer` header |
| **Auth API** | `/auth/v1` | Login/session flows | Session/credentials |

## Data API

Read-only, cache-optimized delivery of published (and draft) content: content entries, blocks, data sources, redirects, search, sitemap, and the Iconify-compatible icon API. Documented in [Data API](data-api.md) and [Querying content](../guides/querying-content.md); auth and caching semantics in [Access tokens & caching](../concepts/access-tokens.md).

- Tokens are space-scoped and created in **Settings → Access tokens**.
- Responses are aggressively cacheable via revision-stamped URLs.
- The official SDKs (`@b10cks/client` and friends) wrap this API.

## Management API

Full read/write access to everything the admin UI can do — content CRUD and publishing, block schemas, assets, data sources, redirects, releases, automations, settings, teams, backups, migrations. Documented in [Management API](management-api.md).

- Authenticate with a **personal access token** (created under **Account → Security**) as a `Bearer` header. Requests act with your permissions.
- Space-scoped endpoints live under `/mgmt/v1/spaces/{space}/…`.

## Auth API

Session endpoints (login, logout, second factor, password reset) used by the admin UI. Most integrations never call it directly — use a personal access token instead.

## Machine-readable reference

Each API's OpenAPI 3 spec can be generated from the code and browsed per instance — see [Generated OpenAPI specs](openapi.md).

## Conventions

- **IDs** are ULIDs (26-character, lexicographically sortable).
- **Pagination** — `page` + `per_page`, responses carry a `meta` object with totals and links.
- **Filtering** — `field=op:value` query syntax; see the [operator table](../guides/querying-content.md#operator-syntax).
- **Sorting** — `sort=field,-other` (leading `-` = descending).
- **Errors** — standard HTTP status codes; validation failures return `422` with per-field messages; the error body has a `message` plus optional `errors` map.
- **Timestamps** are ISO-8601 in UTC.
