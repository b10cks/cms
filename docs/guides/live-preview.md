---
description: "How the preview bridge connects your site to the b10cks visual editor: selectable blocks, inline editing, live updates."
---

# Live Preview & Visual Editing

The b10cks visual editor loads **your real frontend** in an iframe and talks to it through a `postMessage` bridge. Editors see the page exactly as visitors will — same components, same CSS — while clicking blocks to select them, editing text inline, and watching every change render instantly, without saving or reloading.

This page explains how the pieces fit together. For the framework-specific wiring, see the [Nuxt](nuxt.md#4-live-preview--visual-editing), [Vue](vue.md#live-preview--visual-editing), [React](react.md#live-preview--visual-editing), [Next.js](nextjs.md#live-preview--visual-editing), and [Svelte](svelte.md#live-preview--visual-editing) guides.

## The three ingredients

1. **A preview URL.** In the space's visual editor settings you point the editor at your site (e.g. `https://localhost:3000/` during development). The editor opens that URL in an iframe and appends `b10cks_vid` so your app fetches the draft version — which is why your routes should pass that query parameter through as `vid` (see the framework guides).

2. **The preview bridge.** The SDK detects it is running inside an iframe and announces itself to the editor with `B10CKS_BRIDGE_READY` and the protocol version it speaks. It re-announces after every navigation inside the iframe, and the editor replays the current state (content, selection, block labels, hidden blocks) each time. Outside the editor iframe the bridge never initializes, so all preview APIs are no-ops in production. See [Bridge protocol](#bridge-protocol) for the messages.

3. **Editable markers in your components.** The SDK's directives/hooks/actions register DOM elements with the bridge:
   - *Editable block* (`v-editable` / `useEditable` / `use:editable`): clicking the element selects the block in the editor; selection and hover states from the editor highlight it and scroll it into view.
   - *Editable field* (`v-editable-field` / `useEditableField` / `use:editableField`): makes a simple string field contenteditable and streams edits back to the editor. For complex fields (links, assets), use `mode: 'select'` with a `path` so clicking opens the editor's own field editor instead.
   - *Editable rich text* (the `editable` prop of `B10cksRichText`, e.g. `:editable="{ id: block.id, path: ['body'] }"`): clicking the rendered text turns it into a rich text editor in place, with the same formatting options as the field in the editor. See [Rich text in the preview](#rich-text-in-the-preview).
   - *Preview content store* (`usePreviewContent` / `createPreviewContent`): wraps your fetched content tree in a reactive store that applies `CONTENT_UPDATE` and `CONTENT_PATCH` events, so the whole page re-renders live, including nested blocks and rich text.

## Bridge protocol

The current protocol is version 3. The SDK sends it with the ready announcement (`{ protocol: 3 }`); SDKs that send no payload, or don't announce at all, are treated as protocol 0 and only receive `CONTENT_UPDATE`, `SELECT_UPDATE`, and `HOVER_UPDATE`; for SDKs that don't announce, the editor starts sending shortly after each page load. The editor never sends a message newer than the preview's protocol, marked (v1) to (v3) below. Every message is `{ type, payload }`.

Editor to preview:

| Message | Payload | Effect in your app |
| --- | --- | --- |
| `CONTENT_UPDATE` | `{ content }` | Replaces the block whose `id` matches `content.id`, or the whole tree when it carries the entry id. Sent for structural changes. |
| `CONTENT_PATCH` (v1) | `{ itemId?, path, value }` | Replaces the value at `path` inside block `itemId` (the root when omitted). Sent for field edits instead of `CONTENT_UPDATE`. |
| `SELECT_UPDATE` / `HOVER_UPDATE` | `{ selectedItem: string \| null }` | Highlights and scrolls to the selected or hovered block; `null` clears it. |
| `BLOCK_LABELS` (v1) | `{ labels: { [blockSlug]: name } }` | Display names for the selection label, e.g. "Hero banner" instead of `hero_section`. |
| `HIDDEN_BLOCKS` (v2) | `{ ids: string[] }` | Ids of every hidden block in the content, at any depth. Sent when that set changes. The SDK dims those blocks (class `b10cks-hidden`) and offers Hide and Show in the selection toolbar. |
| `FIELD_CONFIG` (v3) | `{ itemId, path, richtext: { features?, headingLevels? } }` | Answer to `FIELD_SELECT` of a rich text field the user may edit: the preview may edit it in place, with the field's settings. |

Preview to editor:

| Message | Payload | Effect in the editor |
| --- | --- | --- |
| `SELECT_UPDATE` | `{ selectedItem }` | Selects the block. |
| `FIELD_UPDATE` | `{ itemId, path, value }` | Writes an inline edit into the block's field: a string, or a rich text document. It updates the form, marks the field changed and reaches collaborators, like an edit in the form. The editor doesn't send it back; the preview already shows it. Ignored for users without edit permission. |
| `FIELD_SELECT` (v1) | `{ itemId, path }` | Opens the block and focuses the field at `path` (e.g. `['body']` or `['items', 2, 'title']`), including rich text. |
| `BLOCK_ACTION` (v1) | `{ itemId, action }` | `move-up`, `move-down`, `duplicate`, `delete`, `insert-before`, `insert-after`, and (v2) `hide`, `show`. Insert opens the editor's block picker at that position. Hide and show set the block's visibility like the eye toggle in the form, and do nothing when it already is in that state. |
| `BLOCK_MOVE` (v1) | `{ itemId, targetId, position: 'before' \| 'after' }` | Drag and drop: moves the block next to `targetId`, possibly into another blocks field. |

The editor answers block actions and moves with `CONTENT_UPDATE`, plus `SELECT_UPDATE` when the selection changes (a duplicate gets selected) and `HIDDEN_BLOCKS` when visibility changes. It applies them like its own form does: users without edit permission can't run them, and it rejects anything the schema forbids (a block the target field doesn't allow, a move into the block's own children, going over or under the field's item limits) with a notice in the editor. The editor ignores malformed messages.

Hiding in the preview only dims a block, so it stays selectable. Whether a hidden block reaches the preview at all is up to the Data API and your site, as in production: with the space setting that filters hidden blocks, or a site that skips blocks with `hidden: true`, it simply isn't there.

## Rich text in the preview

With `editable` set, `B10cksRichText` renders with the SDK's lightweight renderer as usual. When an editor clicks it in the preview, the SDK sends `FIELD_SELECT`, and the editor opens the field and answers with `FIELD_CONFIG` if the user may edit it. The SDK then loads its editor, built on Tiptap with the same schema as the editor's own rich text field, and turns the rendered element into it in place, keeping the site's styles. A small toolbar above the field offers the formats the field allows; keyboard shortcuts work as in the editor, and Escape returns to block selection.

- **Both ways, live.** Typing in the preview reaches the editor as `FIELD_UPDATE` with the field's document, a few times a second and on blur. Edits in the editor's form reach the preview as `CONTENT_PATCH` and are merged into the in-place editor without moving the cursor. The editor doesn't echo preview edits back.
- **Nothing extra in production.** The editor code is loaded with a dynamic `import()` only after that click (or when hovering a field) inside the visual editor. Visitors, server rendering and the preview before activation only use the renderer.
- **No silent content loss.** A document with content the field's schema doesn't know stays with the form: the preview doesn't turn it into an editor. The shared fixture `tests/fixtures/richtext-documents.json` keeps both editors' schemas in step.
- **Permissions.** Users who can't edit content get no `FIELD_CONFIG`, so clicking a rich text field only opens it in the form.

Links to other content, placeholders, tables, text classes and list styles render and survive editing in the preview, but are added and changed in the form.

## Security

- **Origin checks.** Pass `allowedOrigins: ['https://app.b10cks.com']` (or your self-hosted admin origin) to the SDK so the bridge ignores messages from any other origin. Without it, the bridge locks onto the origin of the first valid message (trust-on-first-use).
- **CSP.** If your site sends `Content-Security-Policy`, allow the editor to frame it: `frame-ancestors https://app.b10cks.com` (plus your own origin if needed).
- **Draft access.** Draft content is only served when the request's access token permits it; the preview iframe uses your app's regular token. Nothing about the bridge grants extra API access.

## Scroll offset for fixed headers

When the editor selects a block, the page scrolls it into view. If your site has a fixed header, set an offset so the block isn't hidden underneath it — either as an SDK option (`scrollOffset: 80`) or purely in CSS:

```css
:root {
  --b10cks-scroll-offset: 80px;
}
```

## Checklist

- [ ] Preview URL configured in the space's visual editor settings
- [ ] Routes forward `b10cks_vid` → `vid` when fetching content
- [ ] Content tree wrapped in `usePreviewContent` (or equivalent)
- [ ] Blocks marked with `v-editable` (or equivalent), simple text fields with `v-editable-field`, rich text with `B10cksRichText`'s `editable` prop
- [ ] `allowedOrigins` set; CSP `frame-ancestors` allows the editor origin
- [ ] `scrollOffset` configured if the site has a fixed header
