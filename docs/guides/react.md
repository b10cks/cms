---
description: "Integrate b10cks into a React app with @b10cks/react: provider, hooks, block components, live preview, and images."
---

# React Guide

Integrate b10cks into a React app with [`@b10cks/react`](https://www.npmjs.com/package/@b10cks/react). For Next.js, see the [Next.js guide](nextjs.md), which adds server helpers and a Next-aware provider on top of this package.

## Install

```bash
bun add @b10cks/react @b10cks/client @b10cks/richtext
```

Wrap your app in the provider:

```tsx
import { B10cksProvider } from '@b10cks/react'

function App() {
  return (
    <B10cksProvider
      apiClientOptions={{
        token: import.meta.env.VITE_B10CKS_TOKEN,
        baseUrl: 'https://api.b10cks.com/api', // or your self-hosted /api URL
      }}
      // Optional — live preview inside the visual editor:
      scrollOffset={80}
      allowedOrigins={['https://app.b10cks.com']}
    >
      <Page />
    </B10cksProvider>
  )
}
```

## Map blocks to components

Every content entry is a tree of blocks. `<B10cksComponent>` renders the component registered for a block's technical name — it matches the exact slug (`hero_banner`), its PascalCase form (`HeroBanner`), or lowercase, in that order. Unmatched blocks render a fallback (override it with the `fallback` prop):

```tsx
// components.ts — one registry for the whole app
import { Hero } from './blocks/Hero'
import { Page } from './blocks/Page'
import { Teaser } from './blocks/Teaser'

export const components = { page: Page, hero: Hero, teaser: Teaser }
```

```tsx
// blocks/Page.tsx — a root component renders its nested blocks the same way
import { B10cksComponent } from '@b10cks/react'
import { components } from '../components'

export function Page({ block }: { block: PageBlock }) {
  return (
    <main>
      {block.body.map((b) => (
        <B10cksComponent key={b.id} block={b} components={components} />
      ))}
    </main>
  )
}
```

> **Typed blocks:** generate TypeScript interfaces for all your block schemas with `b10cks generate-types <spaceId>` — see the [CLI guide](cli.md#generate-types).

## Fetch content

```tsx
import { useB10cksApi } from '@b10cks/react'
import { components } from './components'

function ContentPage({ slug }: { slug: string }) {
  const { useContent } = useB10cksApi()
  const page = useContent(slug, { vid: 'published' })

  if (page.pending) return <Spinner />
  if (page.error) return <ErrorView error={page.error} />

  return (
    <B10cksComponent
      block={{ id: page.data.id, block: page.data.block, ...page.data.content }}
      components={components}
    />
  )
}
```

Each hook returns `{ data, pending, error, execute, refresh }`. Single-resource hooks fetch immediately; collection hooks default to `immediate: false` — pass `{ immediate: true }` or call `execute()` when your inputs are ready. Changing inputs (slug, params) re-fetches automatically.

### Routing

The canonical setup is one catch-all route that maps the URL path to a content slug, mirroring the content tree:

```tsx
// React Router
import { useLocation, useSearchParams } from 'react-router'

function CatchAll() {
  const { pathname } = useLocation()
  const [searchParams] = useSearchParams()
  const slug = pathname.replace(/^\/+|\/+$/g, '') || 'home'

  const { useContent } = useB10cksApi()
  const page = useContent(slug, {
    // Honoring b10cks_vid lets the visual editor preview drafts through your real routes
    vid: (searchParams.get('b10cks_vid') as 'draft' | 'published') || 'published',
  })
  // … render as above
}
```

Lists take the same parameters as the Data API's content collection endpoint, including typed filters:

```tsx
const articles = useContents(
  {
    language_iso: 'en',
    vid: 'published',
    sort: '-published_at',
    filter: { content_type: 'article' },
  },
  { immediate: true }
)
```

See [Querying content](querying-content.md) for the full filter and sort reference.

## Live preview & visual editing

The editing hooks are no-ops outside the b10cks visual editor, so they're safe in production output:

```tsx
import { useEditable, useEditableField, usePreviewContent } from '@b10cks/react'

function Hero({ block }: { block: HeroBlock }) {
  // Selectable block: click selects it; the editor highlights and scrolls to it
  const ref = useEditable<HTMLElement>(block.id)

  // Inline-edit a simple string field
  const headlineRef = useEditableField<HTMLHeadingElement>({ id: block.id, field: 'headline' })

  // Complex fields: deep-select so the editor opens its own field editor
  const bodyRef = useEditableField<HTMLDivElement>({ id: block.id, path: ['body'], mode: 'select' })

  return (
    <section ref={ref}>
      <h1 ref={headlineRef}>{block.headline}</h1>
      <div ref={bodyRef}>
        <B10cksRichText document={block.body} />
      </div>
    </section>
  )
}
```

For whole-tree live updates — nested blocks and rich text included — wrap the fetched content in `usePreviewContent`:

```tsx
function Page({ initialContent }: { initialContent: PageContent }) {
  const content = usePreviewContent(initialContent)
  return <BlockRenderer block={content} />
}
```

When `initialContent` changes identity (route change, revalidation, locale switch), the preview resets to the new tree. `usePreviewSelection(blockId)` returns `{ isSelected, isHovered }` if you want to drive your own highlight styling instead of the default.

## Rich text

```tsx
import { B10cksRichText, renderRichTextAsText } from '@b10cks/react'

<B10cksRichText
  document={block.body}
  className="prose"
  options={{
    internalLinkHandler: (attrs) => resolveUrl(attrs.content, attrs.anchor),
    placeholderHandler: (key) => ({ companyName: 'Acme' })[key],
  }}
/>

// Plain text — for search indexing or meta descriptions
const description = renderRichTextAsText(block.body, { blockSeparator: ' ' })
```

See [Rendering rich text](rich-text.md) for the full renderer reference.

## Images

Asset fields deliver a `full_path` that the built-in image service (Ilum) transforms on the fly — append URL operations for resizing, cropping, and format conversion:

```tsx
function ilum(fullPath: string, ops: string) {
  return `${import.meta.env.VITE_ILUM_BASE_URL}/${fullPath}/${ops}`
}

function Hero({ block }: { block: HeroBlock }) {
  return (
    <img
      src={ilum(block.image.full_path, 'w_1600,h_900,c_fill,g_auto')}
      srcSet={[800, 1200, 1600]
        .map((w) => `${ilum(block.image.full_path, `w_${w},h_${Math.round(w * 0.5625)},c_fill,g_auto`)} ${w}w`)
        .join(', ')}
      sizes="100vw"
      alt={block.image.alt}
    />
  )
}
```

Transformed URLs are immutable per asset version and cache indefinitely. The full operation syntax (crop modes, gravity, focal points, `format`/`quality`) is in [Image service (Ilum)](../concepts/image-service.md).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `B10cks data API is missing from context` | A hook is used outside `<B10cksProvider>`, or the provider has no `apiClientOptions`/`client`. |
| 401 from the API | Missing/invalid token, or the space is not live. |
| A block renders the fallback | No entry for its slug (or PascalCase name) in the `components` map. |
| Collection hook never fetches | Collection hooks default to `immediate: false` — pass `{ immediate: true }` or call `execute()`. |
| Draft changes not visible in preview | Your route ignores the `b10cks_vid` query parameter — pass it through as `vid`. |
| Blocks don't highlight in the visual editor | Missing `useEditable` ref on the block's root element, or the editor origin is blocked by `allowedOrigins`/CSP `frame-ancestors`. |
| Edits don't update nested blocks live | Wrap the fetched tree in `usePreviewContent`. |
