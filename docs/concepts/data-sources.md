---
description: "Named key/value datasets with dimensions — feed option fields and query them from the Data API."
---

# Data Sources

A **data source** is a named set of key/value entries — the right tool for structured, non-page data: category lists, country codes, opening hours, feature flags, translation-independent labels. Data sources feed **Option/Options fields** in block schemas and are directly queryable from the Data API.

## Structure

- A data source has a `name`, a `slug` (its API identifier), and optional **dimensions**.
- Each **entry** is a `name`/`value` pair, optionally with per-dimension values.

### Dimensions

Dimensions add a second axis to a data source — most commonly languages or regions. An entry then has a default value plus optional per-dimension overrides, e.g.:

| name | value (default) | value (`de`) |
| --- | --- | --- |
| `greeting` | Hello | Hallo |
| `cta` | Learn more | Mehr erfahren |

Request a dimension in the API to get resolved values for it.

## Feeding option fields

**Option** (single) and **Options** (multi) fields — and option-typed table columns — can select a data source as their choice list instead of defining options inline (`source: 'datasource'`). Manage the list once, and every field using it stays in sync. See [Fields](fields.md#option-single-choice).

## Data API

```
GET /api/v1/datasources?token=…                       # list data sources
GET /api/v1/datasources/{slug}/entries?token=…        # entries of one source
```

```ts
const entries = await dataApi.getDataEntries('categories')
```

Entries endpoints support pagination and dimension selection; responses are cached like other Data API endpoints.

## Import/export

Data sources can be imported and exported (e.g. CSV/JSON) from the admin UI — convenient for spreadsheet-managed lists. See the [data sources user guide](../ui/data-sources.md).
