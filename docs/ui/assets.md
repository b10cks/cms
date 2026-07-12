---
description: "The asset manager: uploading, organizing, collections, metadata, and sharing."
---

# Asset Manager

**Assets** is your media library — every image, video, document, and file your project uses, in one place. If you can use the file manager on your computer, you already know how to use it: folders, multi-select, cut and paste, keyboard navigation. Background concepts: [Assets](../concepts/assets.md) and [Image service](../concepts/image-service.md).

**On this page:** [Browsing](#browsing-and-finding-files) · [Folders](#folders--and-their-rules) · [Tags](#tags) · [Collections](#collections) · [Uploading](#uploading) · [Asset details](#the-asset-detail-view) · [Bulk work](#working-with-many-assets-at-once) · [Sharing](#sharing-assets) · [Import & export](#import--export)

## Browsing and finding files

- **Two views** — a thumbnail grid for visual browsing, a list for dense scanning. The folder tree, tag list, and collections sit in the sidebar.
- **Select like in a file manager** — click; <kbd>Shift</kbd>-click for a range; <kbd>⌘/Ctrl</kbd>-click to add or remove one; *select all*, including everything matching your current filter across pages.
- **Keyboard first, if you like** — arrows move, <kbd>Enter</kbd> opens, typing a name jumps to the first match, cut/paste moves files between folders. Press <kbd>?</kbd> anytime for the shortcut overview.
- **Filter** by file type (image, video, audio, document…), tags, folder, rights status, or license expiry — and combine filters ("images tagged *hero* whose license expires before March").

## Folders — and their rules

Folders organize the library like on disk: create, rename, move, nest. Two things make them more than boxes:

- **Deleting a folder** never silently deletes files — the app guards against removing folders that still have content.
- **Folder metadata rules** — each folder can decide which information files inside it must carry. Open a folder's settings to:
  - **require or relax inherited fields** — make alt text mandatory in `Website images`, optional in `Internal drafts`
  - **switch fields off** where they don't apply
  - **add folder-only fields** — a `photographer` field that exists just inside `Press photos`

  Child folders inherit these rules automatically, so one setting covers a whole branch. Uploads into the folder ask for exactly what that folder demands. ([Concept](../concepts/assets.md#per-folder-metadata-rules))

## Tags

Tags cut across folders — an image lives in *one* folder but can carry many tags (`hero`, `2026`, `campaign-spring`). Each tag has a name, a color, and an icon, so tag chips stay recognizable. Deleting a tag never touches the assets; it just removes the label. Use **Bulk tag** to add or remove tags on a whole selection at once.

## Collections

Collections sit below folders and tags in the sidebar, but group assets *across* folders — the same file can appear in several collections. Create one with the **+** on the collection list. Each has a name, icon, and color; open one and its assets fill the grid with the collection's name in the breadcrumb. Two kinds ([concept](../concepts/assets.md#collections)):

- **Manual** — curate by hand: drag assets onto the collection, or use **Add to collection** from an asset's menu or the selection bar. *Remove from collection* takes an asset out again without deleting the file.
- **Smart** — instead of adding files you define rules: pick a field (filename, tags, size, folder, orientation, rights status, dates …), an operator, and a value, and match **all** or **any** conditions. The collection fills itself and stays current as assets change.

> **Remove vs. delete** — inside a collection, *Remove from collection* only unlinks the asset from that collection; *Delete from library* permanently removes the asset everywhere it's used. The menu labels and the confirmation dialog spell out the difference so the two are never confused.

## Uploading

Drag files anywhere into the library (or click *Browse files*). Before the upload finishes, the details dialog walks you through what your space needs:

- **Required information is collected now** — if alt text is mandatory here, you're asked for it upfront rather than chased later. A counter shows which files still miss required details.
- **Duplicate detection** — if a file looks identical to something already in the library, b10cks offers to **use the existing asset** instead of storing a second copy (or upload anyway, your call).
- Images get their **colors and dimensions** read automatically; videos get preview thumbnails.
- Metadata fields can be filled per language where your space is multilingual.

## The asset detail view

Open any asset for the full picture — and use the arrow buttons to step through the folder without closing the view.

### Details & metadata

Filename, alt text, title, description, and any custom fields your space (or this folder) defines — with per-language values for translatable fields. An *unsaved changes* hint keeps you from navigating away mid-edit.

### Focus point

Click the image to set its **focus point** — the spot that must stay visible when the website crops the image to different shapes (wide header, square card, tall banner). One image, every format, face always in frame. ([How cropping works](../concepts/image-service.md#crop-modes))

### Colors & accessibility

The extracted color palette with, per color: contrast ratios against black and white, and the recommended overlay (light or dark text) — so design decisions about text-on-image are informed, not guessed. Click any color to copy it.

### Rights & licensing

Copyright holder, license type (proprietary, CC0, CC BY, …), usage restrictions, and expiry date. Assets show their rights status (*unrestricted / restricted / expired*) as a visible indicator — informational, so workflows aren't blocked, but nobody can say they didn't see it. Filter the library by rights status or upcoming expiry to audit before a campaign.

### Linked content

Every page using this asset, with its draft/published state — so before you replace or delete an image, you know exactly what it would touch.

### Version history

**Replace media** swaps the file while keeping the asset's identity — every page using it gets the new file automatically, no re-linking. The previous file is kept as a version; restoring an old version is itself versioned, so even the undo can be undone.

### Quick actions

Copy the file's URL, open it in a new window, download it, duplicate the asset, or delete it (with a confirmation — and a stronger one if it's still linked somewhere).

## Working with many assets at once

Select multiple assets and the selection bar offers the bulk moves: **move to folder** (with folder search), **bulk tag** (add and remove in one dialog), **add to collection**, **share**, **download**, **export**, and **delete**. In a manual collection it also offers **remove from collection**. Mixed selections of folders and files drag together.

## Sharing assets

Publish a **collection**, a **folder**, or a **selection** as a public download link — recipients open it with no b10cks account ([concept](../concepts/assets.md#sharing--downloads)).

- **Create & manage** — from a collection's menu choose **Manage shares** to list its links, create new ones, copy or open them, and edit, revoke, or delete existing ones. Every link is also listed under **Settings → Shares**.
- **Protect** — set an optional password, an expiry date, and a maximum number of downloads; allow or block single-file downloads.
- **What recipients see** — a clean page with thumbnail previews and a **Download all** (a server-built zip), plus per-file downloads when enabled. Revoking a link cuts off access immediately.

## Import & export

**Export** writes the metadata of your assets (or a selection) to JSON, CSV, Excel, XLIFF, or YAML. **Import** applies an edited file back and reports, row by row, what succeeded, what changed, and what failed.

Round-trip through a spreadsheet for mass cleanups — fixing hundreds of alt texts, standardizing titles — or send the XLIFF to a translation agency and import the translated metadata back.
