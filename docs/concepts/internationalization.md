---
description: "Languages, translatable fields, the overlay model, fallbacks, and localized slugs."
---

# Internationalization

b10cks localizes content with a **canonical + overlay** model: one canonical entry per piece of content, plus lightweight translation entries per language that override only what actually differs.

## Languages

Enable languages and pick the default in **Settings → Internationalization**. The default language is what canonical entries are authored in and what the Data API serves when no `language` is requested.

Per language you configure:

- **Mode** — `overlay` (the language behaves as a translation layer over the canonical content; the default model described below) or `independent` (the language maintains standalone pages).
- **Fallback** — the direct fallback language used when a value is missing, letting you build chains like `de-AT → de → en`.

Two URL-related settings decouple site URLs from CMS languages:

- **Slug strategy** — how localized slugs are built.
- **Site locales** — map URL path segments to languages; one language can serve several segments (e.g. `de` under `at-de`, `ch-de`, and `de-de`).

## Canonical entries and translations

- The **canonical entry** holds the full content in the default language.
- A **translation** is a linked entry (`i18n_parent_id` → canonical) for one target language, with its own slug, name, version history, and publish state.

Which fields a translation can override is controlled by the block schema: only fields marked **translatable** ([Fields](../concepts/fields.md#common-settings)) get per-language values. At delivery time the resolver **overlays** the translation onto the canonical version:

- translatable fields → the translation's value, falling back to the canonical value when the translation leaves them empty
- non-translatable fields (layout choices, assets, nested structure, numbers, …) → always the canonical value

This keeps translations cheap to maintain: structural changes to a page happen once, on the canonical entry, and every language picks them up automatically.

## Localized slugs

Translations have their own slugs, so `/en/about-us` and `/de/ueber-uns` can both resolve — the Data API matches localized slug paths when a `language` is requested.

## Querying localized content

```
GET /api/v1/contents/ueber-uns?language=de
GET /api/v1/contents?language=de&include_fallback=true
```

- `language` (alias `language_iso`) selects the language.
- **Single entries** always resolve with fallback — you get the entry in the requested language, overlaid on the canonical.
- **Collections** by default return only entries that *have* a translation in the requested language. Add `include_fallback=true` to also include canonical entries without one (served with fallback values), so listings are complete per language.
- `canonical_id` / `canonical_parent_id` filters address an entry *across* languages: "give me this entry (or this folder's children) in German, whatever the German variant's own ID is". See [Querying content](../guides/querying-content.md).

Responses include the entry's language and its available siblings/translations, so frontends can build language switchers.

## Publishing translations

Each translation publishes independently — German can go live days after English. An unpublished translation simply isn't served; with `include_fallback=true`, listings serve the canonical entry in its place.

## Translation workflows in the app

The editor shows translatable fields side-by-side with the canonical value, supports **AI-assisted translation** per field or per entry (when AI is configured for the space), and content translations can be exported/imported for external translation workflows. See the [content editor user guide](../ui/content.md).
