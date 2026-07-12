---
description: "Render b10cks rich text fields as HTML or plain text with @b10cks/richtext: links, placeholders, and custom classes."
---

# Rendering Rich Text

Rich text fields store a ProseMirror-style JSON document. The [`@b10cks/richtext`](https://www.npmjs.com/package/@b10cks/richtext) package converts these documents to HTML or plain text — zero dependencies, SSR-safe, ~2 kB gzipped. The framework packages (`@b10cks/vue`, `@b10cks/react`, `@b10cks/svelte`, `@b10cks/nuxt`, `@b10cks/next`) wrap it in a `B10cksRichText` component and forward all options described here.

## Basic rendering

```ts
import { renderRichText, renderRichTextAsText } from '@b10cks/richtext'

const html = renderRichText(block.body)          // HTML string
const text = renderRichTextAsText(block.body)    // plain text, blocks joined by '\n\n'
```

`null`/`undefined` documents render as an empty string. For constant options across many documents, create a reusable renderer with `createRichTextRenderer(options)` / `createRichTextTextRenderer(options)`.

Plain-text rendering is useful for search indexing and meta descriptions:

```ts
const description = renderRichTextAsText(block.body, { blockSeparator: ' ' })
```

## Internal links

Editors link to other content entries; the document stores the target content **ID**, not a URL:

```json
{ "type": "internalLink", "attrs": { "content": "01ksarpy7hd…", "anchor": null } }
```

Resolve IDs to URLs for your routing scheme with `internalLinkHandler`:

```ts
renderRichText(document, {
  internalLinkHandler: (attrs) => {
    const slug = slugMap[attrs.content ?? '']
    return slug ? `/${slug}${attrs.anchor ? `#${attrs.anchor}` : ''}` : null
  },
})
```

Returning `null`/`undefined` falls back to `href="#"`. Rendered internal links carry `data-type="internal"` and `data-b10cks-internal-link`, so a client-side router can intercept them:

```ts
document.addEventListener('click', (e) => {
  const link = (e.target as HTMLElement).closest('a[data-b10cks-internal-link]')
  if (link) {
    e.preventDefault()
    router.push(link.getAttribute('href')!)
  }
})
```

## Placeholder tokens

Rich text fields can be configured with placeholder tokens — variables like `{companyName}` stored as atomic nodes. Substitute values at render time:

```ts
renderRichText(document, {
  placeholderHandler: (key, label) => ({ companyName: 'Acme Inc' })[key] ?? null,
})
```

Unresolved tokens (handler returns `null`) render as `<span data-type="placeholder-token" data-key="…" data-label="…">` so they can be replaced client-side. In plain-text rendering, unresolved tokens emit nothing.

## Styling hooks from the editor

Two field-level configurations surface as plain CSS classes in the output, so any CSS framework can style them:

- **Text classes** (`html_classes` in the field config) render as `<span class="…">`.
- **List styles** (`list_styles`) render as a `class` on `<ul>`/`<ol>`, e.g. `<ul class="checklist">`.

Define the class names in the block's rich text field configuration and style them in your frontend.

## URL safety

Link `href` and image `src` values are validated against a scheme allowlist before rendering — stored `javascript:` URLs cannot execute. Disallowed schemes render as `#`; relative URLs, anchors, and query-only URLs always pass. The default allowlist is `http`, `https`, `mailto`, `tel` (exported as `DEFAULT_ALLOWED_SCHEMES`):

```ts
import { renderRichText, DEFAULT_ALLOWED_SCHEMES } from '@b10cks/richtext'

renderRichText(document, { allowedSchemes: ['https', 'mailto'] })
```

## Supported nodes and marks

| Node | Output | | Mark | Output |
| --- | --- | --- | --- | --- |
| `paragraph` | `<p>` | | `bold` | `<strong>` |
| `heading` | `<h1>`–`<h6>` | | `italic` | `<em>` |
| `blockquote` | `<blockquote>` | | `strike` | `<s>` |
| `codeBlock` | `<pre><code>` | | `underline` | `<u>` |
| `bulletList` / `orderedList` / `listItem` | `<ul>` / `<ol>` / `<li>` | | `code` | `<code>` |
| `hardBreak` / `horizontalRule` | `<br>` / `<hr>` | | `link` | `<a href>` |
| `image` | `<img>` | | `internalLink` | `<a data-type="internal">` |
| `table` family | `<table>` / `<tr>` / `<th>` / `<td>` | | `textClass` | `<span class>` |
| `placeholderToken` | resolved value or data-attributed `<span>` | | | |

Which of these editors can actually produce is controlled per field — heading levels, feature toggles (bold, tables, code blocks, …), and link kinds are all configurable in the rich text field's settings. See [Fields](../concepts/fields.md#richtext).
