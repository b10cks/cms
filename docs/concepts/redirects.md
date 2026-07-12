---
description: "Centrally managed URL redirects with hit tracking, delivered through the Data API."
---

# Redirects

Redirects map old URLs to new ones — after restructuring content, renaming slugs, or migrating from another system. They are managed centrally in the space and delivered through the Data API, so every frontend consuming the space applies the same rules.

## Model

| Property | Description |
| --- | --- |
| `source` | The incoming path, e.g. `/old-blog/hello` |
| `target` | Where to send the visitor, e.g. `/blog/hello` |
| `status_code` | HTTP status — `301` (permanent) or `302` (temporary) |

Hits are tracked per redirect, so you can see which rules still matter and retire dead ones.

## Delivery

Two complementary endpoints:

```
GET  /api/v1/redirects?token=…          # the full rule list (paginated, cacheable)
POST /api/v1/redirects/lookup           # resolve a single source path
```

- **Bulk fetch** (`getRedirects()` in the SDKs) returns a map `source → { target, status_code }`, cached client-side — ideal for route middleware that consults it on every navigation. See the [Nuxt guide](../guides/nuxt.md#7-redirects) for the pattern.
- **Lookup** (`lookupRedirect(source)`) resolves one path server-side — ideal for 404 handlers on high-traffic sites where shipping the whole map is wasteful. Returns `false` when nothing matches.

## Import

Redirect lists can be imported in bulk (e.g. when migrating a site), with configurable handling of existing rules. See the [redirects user guide](../ui/redirects.md).
