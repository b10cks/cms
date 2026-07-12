---
description: "Managing the space icon registry: uploading SVGs, organizing, and using icons."
---

# Icons

The **Icons** section is your project's own icon set. Upload your brand's SVG icons here and they become available everywhere — for editors to pick in content, and for developers through the same tooling they use for public icon sets ([how that works](../concepts/icons.md)).

## Uploading

Drop `.svg` files into the upload dialog (single or batch). Each icon gets:

- **Key** — lowercase letters, numbers, hyphens; unique per space; this is the icon's API name
- **Name / description** — for humans and search
- **Tags** — for filtering (batch uploads can apply tags to all files at once)

## Managing icons

The library lists all icons with search (by key or name) and tag filtering. Editing an icon offers:

- **Replace SVG** — upload a new file for the same key
- **Edit SVG source** — tweak the markup directly, adjust width/height
- **Replace colors** — rewrites fixed `fill`/`stroke` colors to `currentColor`, so the icon inherits the text color where it's used (changes the SVG source)
- **Color check** — preview against light, dark, and transparent backgrounds

## Using icons

- **In content** — add an [Icon field](../concepts/fields.md#icon) to a block; editors pick from the registry (and optionally public Iconify collections, depending on the field's `source` setting).
- **In frontends** — consume the registry through the Iconify-compatible endpoints (`/api/v1/iconify/…`) with any Iconify tooling, or fetch single SVGs directly. See [Icons (concept)](../concepts/icons.md#delivery-api).
