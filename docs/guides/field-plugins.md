---
description: "Build custom field editors for b10cks: the plugin bundle contract, the postMessage bridge, manifest options, dev mode, and the sandbox's security limits."
---

# Field Plugins

A **field plugin** is your own editor UI for a content field — a color picker, a map picker, a product lookup against your ERP, a rating widget. It runs in a sandboxed iframe inside the content editor, talks to the editor over a small message bridge, and stores whatever JSON value it produces. Your frontend receives that value unchanged.

Plugins are registered per space in **Settings → Plugins** and used from a block schema through the [`plugin` field type](../concepts/fields.md#plugin):

```json
{
  "type": "plugin",
  "plugin_handle": "color-picker",
  "options": { "preset": "brand" }
}
```

The handle is immutable after creation, so schemas referencing a plugin never break.

## The bundle contract

A plugin is a **single self-contained JavaScript file** (up to 1.5 MB) that assigns a global:

```js
window.b10cksFieldPlugin = {
  mount(element, api) {
    // Render your UI into `element`.
    // `api.data` holds the INIT payload (see below).
    let value = api.data.value ?? ''

    const input = document.createElement('input')
    input.value = String(value)
    input.disabled = api.data.context.readOnly
    input.addEventListener('input', () => api.setValue(input.value))
    element.appendChild(input)

    // Anything you return is optional — the editor calls these on updates.
    return {
      onValueUpdate(next) { input.value = String(next ?? '') },
      onReadOnlyUpdate(readOnly) { input.disabled = readOnly },
      onTheme(theme) { element.dataset.theme = theme },
    }
  },
}
```

The bundle is injected as a **classic script**, not a module: bare `import` statements will not run. Bundle with an IIFE/UMD target (`format: 'iife'` in esbuild/Rollup, `library.type: 'window'` in webpack) and inline every dependency. `process.env.NODE_ENV` is shimmed to `'production'` before your code runs, so React and friends load without a bundler define.

### `api.data` — the INIT payload

```ts
{
  value: unknown,                       // the field's current value, or null
  options: Record<string, string>,      // manifest defaults merged with the schema's `options`
  context: {
    spaceId: string,
    fieldKey: string,
    language?: string,          // reserved; not sent by the current editor
    readOnly: boolean,
    isModal: boolean,
  },
  theme: 'light' | 'dark',
}
```

### `api` — what you can call

| Call | Effect |
| --- | --- |
| `api.setValue(value)` | Stores a new value. Any JSON-serializable value works; it lands in the content version as-is. |
| `api.setHeight(px)` | Sets the iframe height. Clamped to 50–1200 px. |
| `api.toggleModal(open)` | Asks the editor to expand the plugin into a modal — for pickers that need room. |
| `api.selectAsset()` | Reserved for asset picking. Currently rejects with `unsupported`; handle the rejection. |

Height is also observed automatically: the shell watches the document with a `ResizeObserver` and reports changes, so most plugins never call `setHeight` themselves.

The editor must see your plugin register itself within **8 seconds** of the iframe loading, otherwise it shows a load-failed state with a retry button.

## Manifest options

The manifest declares the settings a schema may pass, so one plugin can serve several configurations:

```json
{
  "height": 240,
  "options": [
    { "key": "preset", "label": "Preset", "default": "brand" },
    { "key": "format", "label": "Output format", "default": "hex" }
  ]
}
```

- `height` — the initial iframe height (50–1200) used until the plugin reports its own.
- `options[]` — `key` (≤ 64 chars), optional `label` and `default`. Values are strings. The schema's `options` object overrides the defaults, and the merged result arrives as `api.data.options`.

## Publishing a bundle

In **Settings → Plugins**:

1. **Create** the plugin with a name and a handle (lowercase letters, digits, hyphens — permanent).
2. Open it, paste the bundle into the code field or pick the `.js` file from disk.
3. **Publish** stores the bundle, hashes it, and marks the plugin published. **Save** keeps the metadata change without touching the current bundle.

Published bundles are served from a signed sandbox URL pinned to the bundle hash (`?v=…`), so a published version caches for a year and a new publish invalidates it immediately. The plugin list shows the status — *draft* (no bundle yet), *dev*, or *published* — and the bundle size.

Deactivating a plugin (`is_active = false`) drops its sandbox URL and makes the sandbox endpoint 404; fields using it show the load-failed state rather than stale code.

## Dev mode

While building, point the plugin at a local dev server instead of re-uploading bundles:

- Turn on **Dev mode** and set the **Dev URL** — it must be `localhost`, `127.0.0.1`, or `[::1]`. Remote hosts are rejected: an arbitrary host here would mean arbitrary script in every editor session in the space.
- The editor frames your dev URL directly, without the sandbox shell. Your dev page has to speak the protocol itself (see below) — the convenience shell only wraps published bundles.
- The frame never gets `allow-same-origin`, so HMR clients that rely on same-origin access will not work. Reload the frame instead.

## The message protocol

If you write your own shell (dev mode), this is the full protocol. Every message carries `source: 'b10cks-plugin'`, `version: 1`, and the handshake `token` the editor minted for this mount. The token arrives in the URL fragment as `#b10cks-token=…` — it never reaches the server. Messages that fail any of these checks are ignored on both sides.

| Direction | Type | Payload |
| --- | --- | --- |
| host → plugin | `INIT` | the `api.data` payload above |
| host → plugin | `VALUE_UPDATE` | `{ value }` — the value changed outside the plugin |
| host → plugin | `READ_ONLY_UPDATE` | `{ readOnly }` |
| host → plugin | `THEME` | `{ theme: 'light' \| 'dark' }` |
| host → plugin | `ASSET_SELECT_RESULT` | `{ requestId, asset, error? }` |
| plugin → host | `PLUGIN_READY` | `{}` — send once the bundle is mounted; the host answers with `INIT` |
| plugin → host | `VALUE_CHANGE` | `{ value }` |
| plugin → host | `HEIGHT_CHANGE` | `{ height }` |
| plugin → host | `MODAL_TOGGLE` | `{ open }` |
| plugin → host | `ASSET_SELECT_REQUEST` | `{ requestId, fileTypes? }` |

Post to `window.parent` with `targetOrigin: '*'` — a sandboxed frame has an opaque origin, so nothing narrower would be delivered.

## What the sandbox allows

The iframe runs with `allow-scripts allow-forms allow-popups allow-modals` and deliberately **without** `allow-same-origin`. Combined with `allow-scripts`, same-origin would void the sandbox, and the shell is served from the admin's own origin — plugin code would gain the session cookie, the CSRF token, and `window.parent.document`.

The shell's Content-Security-Policy adds:

| Directive | Consequence for your plugin |
| --- | --- |
| `default-src 'none'` | No implicit network access of any kind |
| `script-src 'unsafe-inline'` | Your bundle runs, but **no external scripts** — no CDN, no lazy `import()` |
| `style-src 'unsafe-inline'` | Inline styles and `<style>` blocks only |
| `img-src data: https:` / `font-src data: https:` | Remote images and fonts must be HTTPS |
| `connect-src https:` | `fetch`/XHR to HTTPS endpoints only |
| `frame-ancestors <app origin>` | The shell can only be framed by the CMS |

Because the origin is opaque, `localStorage`, `sessionStorage`, and cookies are unavailable — keep all state in the field value or in `options`.

If your plugin calls an API of your own, that API needs permissive CORS for a `null` origin, and it must not rely on the CMS session. Ship credentials as plugin `options` only when they are safe to expose — anyone who can open the editor can read them.

## Related

- [Fields → Plugin](../concepts/fields.md#plugin) — the schema side
- [Space settings → Plugins](../ui/settings.md#plugins) — the admin screen
