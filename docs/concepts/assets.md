---
description: "The asset library: folders, tags, collections, metadata and rights management, sharing, and delivery."
---

# Assets

Every space has an asset library for images, video, audio, documents, and archives. Assets uploaded here are delivered through the [image service](image-service.md) (for images) or as files, and referenced from content via the **Asset** / **Multi assets** [field types](fields.md#media--reference-fields).

## Organization

- **Folders** — a hierarchical structure, with per-folder settings.
- **Tags** — cross-cutting labels for filtering and search.
- **Metadata** — per asset: alt text, title/description, copyright and **rights status**, plus technical data extracted on upload (dimensions, duration, file size, MIME type).

The asset manager UI supports Finder-style multi-select, bulk operations, drag-and-drop moves, and context menus — see the [asset manager user guide](../ui/assets.md).

## Collections

Where a folder is an asset's home, **collections** group assets *across* folders — one asset can belong to many collections while living in a single folder. Two kinds:

- **Manual collections** — membership you curate by hand (add, remove, or drag assets in). A hand-picked set: a campaign's hero shots, a press kit.
- **Smart collections** — defined by rules rather than membership. You describe conditions over asset properties (filename, extension, MIME type, size, folder, tags, orientation, rights status, license/created/updated dates, untagged) and match **all** or **any** of them. Membership is evaluated on the fly, so the collection stays current as assets change.

Each collection carries a name, icon, and color. See the [asset manager guide](../ui/assets.md#collections).

## Versions

Replacing an asset's file creates a new **asset version** while keeping the same asset identity — content referencing the asset picks up the new file without editing every entry.

## Metadata & rights management

The metadata fields the library collects are configurable per space (**Settings → Asset Library**) — add custom fields, mark them required, and optionally enable the **Rights & Licensing** group: copyright holder, license type (proprietary, CC0, CC BY, …), usage restrictions, and a license expiry date. Rights status (`unrestricted` / `restricted` / `expired`) is surfaced on assets as an informational indicator; asset metadata itself is translatable per language.

### Per-folder metadata rules

Folders can adapt the metadata schema to their content — a distinctive b10cks touch that keeps requirements where they belong:

- **Field overrides** — enable/disable inherited fields or change whether they're required, per folder. Make alt text mandatory in `Website images` but optional in `Internal drafts`.
- **Additional fields** — folder-only custom fields, e.g. a `photographer` field that exists only inside `Press photos`.

Uploads into a folder prompt for exactly the fields that folder demands — editors can't skip what matters, and aren't nagged about what doesn't.

## Import & export

Asset metadata can be exported and re-imported in bulk (JSON, CSV, Excel, XLIFF, YAML) — useful for spreadsheet-based cleanup or external translation of alt texts. Imports report a per-row summary of changes and errors.

## Sharing & downloads

A **collection**, a **folder**, or an ad-hoc **selection** can be published as a public **share** — a link recipients open without a b10cks account to preview and download the assets.

- **Access control** — an optional password, an expiry date, and a download limit, each revocable at any time. Previews are served through a short-lived, share-scoped endpoint rather than a permanent asset URL, so revocation, expiry, and limits actually hold.
- **Packages** — downloading the whole set builds a **package** (a server-side zip) on demand; per-file downloads can be allowed or disallowed per share. Expired packages are pruned automatically and rebuilt on the next request.
- **Metering** — views and downloads are counted per share.

Links are managed per collection (**Manage shares**) or centrally under **Settings → Shares**. See the [asset manager guide](../ui/assets.md#sharing-files-with-people-outside-your-team).

## Delivery

Asset fields deliver an object including `full_path` — the stable path you feed to the [image service](image-service.md) for transformations, or serve directly for non-image files. Signed delivery and CDN setups are transparent to your frontend: always build URLs from `full_path`.

Non-image assets go through the same host, streamed rather than transformed: byte-range requests are supported (so video seeks and plays everywhere, Safari included), responses revalidate via `ETag`, and appending `?download=1` turns an inline response into a download with the original filename. Videos additionally expose a [poster frame](image-service.md#poster-frames) at `…/{filename}/poster`, which accepts the same transformation operations as any image; other non-image assets expose the same sub-path once a custom poster has been uploaded for them.
