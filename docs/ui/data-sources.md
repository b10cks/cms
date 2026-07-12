---
description: "Managing data sources and their entries: dimensions, translation, and import/export."
---

# Data Sources

**Data sets** hold the lists your content relies on — categories, countries, departments, opening hours. Manage a list once here, and every dropdown that uses it stays in sync everywhere; developers can read the same lists through the API. Background: [Data sources](../concepts/data-sources.md).

## Creating a data source

A data source has:

- **Name** and **technical name (slug)** — the slug is the API identifier (`/datasources/{slug}/entries`); a copy-endpoint shortcut is on the list
- **Description**
- **Dimensions** — additional value axes per entry (e.g. languages, regions). Mark dimensions as **translatable** to unlock AI translation of entries
- **Cache duration** — per-source TTL for the Data API (0 disables caching)
- **API availability** — whether the source is exposed via the Data API at all (disabled sources remain usable inside the CMS, e.g. for option fields)

## Managing entries

Each entry is a **key → value** pair, plus one value per dimension. Two editing modes:

- **Single edit** — form-based, one entry at a time
- **Grid edit** — spreadsheet-style editing of all entries and dimensions at once, with auto-save (values can span multiple lines)

Entries can be searched (by key or value), paginated, activated/deactivated, and deleted.

### AI translation

With translatable dimensions and AI enabled, **Translate missing entries** fills the gaps for a dimension in one batch, with progress reporting.

## Import & export

- **Export** — download all entries as JSON, CSV, Excel, or YAML.
- **Import** — upload a file to create or update entries in bulk, with a choice of import mode (add vs. update behavior) and a summary of created/changed/skipped rows.

## Usage

- **Option/Options fields** (and option table columns) can use a data source as their choice list — see [Fields](../concepts/fields.md#option-single-choice).
- Frontends query entries via `GET /api/v1/datasources/{slug}/entries` — see [Data sources](../concepts/data-sources.md#data-api).
