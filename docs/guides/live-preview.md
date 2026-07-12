---
description: "How the preview bridge connects your site to the b10cks visual editor: selectable blocks, inline editing, live updates."
---

# Live Preview & Visual Editing

The b10cks visual editor loads **your real frontend** in an iframe and talks to it through a `postMessage` bridge. Editors see the page exactly as visitors will — same components, same CSS — while clicking blocks to select them, editing text inline, and watching every change render instantly, without saving or reloading.

This page explains how the pieces fit together. For the framework-specific wiring, see the [Nuxt](nuxt.md#4-live-preview--visual-editing), [Vue](vue.md#live-preview--visual-editing), [React](react.md#live-preview--visual-editing), [Next.js](nextjs.md#live-preview--visual-editing), and [Svelte](svelte.md#live-preview--visual-editing) guides.

## The three ingredients

1. **A preview URL.** In the space's visual editor settings you point the editor at your site (e.g. `https://localhost:3000/` during development). The editor opens that URL in an iframe and appends `b10cks_vid` so your app fetches the draft version — which is why your routes should pass that query parameter through as `vid` (see the framework guides).

2. **The preview bridge.** The SDK detects it is running inside an iframe and announces itself to the editor (`B10CKS_BRIDGE_READY`). From then on the editor pushes events into the page:

   | Event | Effect in your app |
   | --- | --- |
   | `CONTENT_UPDATE` | Full content tree replaced (e.g. after structural changes) |
   | `CONTENT_PATCH` | A single block's fields patched in place |
   | `FIELD_UPDATE` | A field value streamed while typing |
   | `SELECT_UPDATE` / `HOVER_UPDATE` | A block selected/hovered in the editor — the page highlights and scrolls to it |
   | `FIELD_SELECT` | A specific field selected for editing |

   Outside the editor iframe the bridge never initializes, so all preview APIs are no-ops in production.

3. **Editable markers in your components.** The SDK's directives/hooks/actions register DOM elements with the bridge:
   - *Editable block* (`v-editable` / `useEditable` / `use:editable`): clicking the element selects the block in the editor; selection and hover states from the editor highlight it and scroll it into view.
   - *Editable field* (`v-editable-field` / `useEditableField` / `use:editableField`): makes a simple string field contenteditable and streams edits back to the editor. For complex fields (rich text, links, assets), use `mode: 'select'` with a `path` so clicking opens the editor's own field editor instead.
   - *Preview content store* (`usePreviewContent` / `createPreviewContent`): wraps your fetched content tree in a reactive store that applies `CONTENT_UPDATE`/`CONTENT_PATCH`/`FIELD_UPDATE` events, so the whole page re-renders live — including nested blocks and rich text.

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
- [ ] Blocks marked with `v-editable` (or equivalent), simple text fields with `v-editable-field`
- [ ] `allowedOrigins` set; CSP `frame-ancestors` allows the editor origin
- [ ] `scrollOffset` configured if the site has a fixed header
