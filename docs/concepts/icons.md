---
description: "Per-space SVG icon registry with an Iconify-compatible delivery API."
---

# Icons

Every space is its own **Iconify-compatible icon registry**. Upload your brand's SVG icons once, and they behave exactly like the public Iconify icon sets your developers already use — same API, same tooling, same one-liner in code. Editors pick icons in content; designers manage them without a deployment; frontends render them with `@nuxt/icon`, `unplugin-icons`, Iconify web components, or plain SVG.

That combination is hard to find elsewhere: most CMSs treat icons as ordinary image assets. b10cks treats them as a first-class, searchable, protocol-compatible icon set — *plus* it gives your editors curated access to the 200,000+ icons of the public Iconify universe when you want it.

## The registry

Upload SVGs individually or in bulk (**Icons** in the space navigation). Icons are organized into collections and delivered under a space-specific prefix. Because the API speaks the Iconify protocol, standard tooling consumes your CMS-managed icons exactly like public icon sets — no custom pipeline, no sprite builds.

## The Icon field

The **Icon** field type stores an icon reference in content. Its `source` option scopes what editors can pick:

- `registry` — only the space's own icons
- `all` — the registry plus any public Iconify collection
- `collections` — the registry plus an allow-list of Iconify collections

See [Fields](fields.md#icon).

## Delivery API

Iconify-compatible endpoints under the Data API (authenticated with the space token):

```
GET /api/v1/iconify/collections            # available collections
GET /api/v1/iconify/{prefix}.json          # icon data (Iconify JSON)
GET /api/v1/iconify/{prefix}.svg           # sprite
GET /api/v1/iconify/{prefix}.css           # CSS
GET /api/v1/iconify/{prefix}/{name}.svg    # single icon as SVG
GET /api/v1/iconify/{prefix}/{name}.css    # single icon as CSS
GET /api/v1/iconify/search?query=…         # search
GET /api/v1/iconify/last-modified          # cache validation
```

Responses are cached like other Data API endpoints.

## Related

- [Icons (user guide)](../ui/icons.md) — uploading and managing icons in the app
