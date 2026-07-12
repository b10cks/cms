---
description: "Use b10cks with the Next.js App Router: server components, metadata, a next/image loader, middleware redirects, and sitemap."
---

# Next.js Guide

Integrate b10cks into a Next.js app with [`@b10cks/next`](https://www.npmjs.com/package/@b10cks/next), which layers Next-specific helpers over `@b10cks/react`.

## Install

```bash
bun add @b10cks/next @b10cks/react @b10cks/client @b10cks/richtext
```

Wrap your Next config:

```ts
// next.config.ts
import { withB10cks } from '@b10cks/next'

export default withB10cks({
  reactStrictMode: true,
})
```

## Server components (App Router)

Fetch content on the server with `defineB10cksNextApi`. It wraps client creation in React's `cache()`, so each request render gets its own client while a single request reuses one instance — safe to export at module scope:

```ts
// lib/b10cks.ts
import { headers } from 'next/headers'
import { defineB10cksNextApi } from '@b10cks/next/server'

export const getB10cks = defineB10cksNextApi(() => ({
  token: process.env.B10CKS_TOKEN || '',
  baseUrl: 'https://api.b10cks.com/api',
  fetchClient: fetch,
  requestUrl: new URL(headers().get('x-url') ?? 'http://localhost'),
}))
```

```tsx
// app/[[...slug]]/page.tsx
import { getB10cks } from '@/lib/b10cks'

export default async function Page({ params }: { params: { slug?: string[] } }) {
  const { dataApi } = getB10cks()
  const content = await dataApi.getContent(params.slug?.join('/') || 'home')

  return <PageRenderer initialContent={content} />
}
```

> **Do not hoist `createB10cksNextApi()` into a module-level singleton.** The client holds request-scoped state — the content revision (`rv`, which a preview/draft request pins) and per-instance caches. A shared instance would let one visitor's draft revision bleed into every subsequent request. Create it per request, or use `defineB10cksNextApi` as above.

For one-off use (route handlers, middleware), create the client per request:

```ts
import { createB10cksNextApi } from '@b10cks/next/server'

const { dataApi } = createB10cksNextApi({
  token: process.env.B10CKS_TOKEN || '',
  baseUrl: 'https://api.b10cks.com/api',
  fetchClient: fetch,
})
```

## Client components

Use the provider from `@b10cks/next/client` for client-side fetching and live preview:

```tsx
'use client'

import { B10cksNextProvider } from '@b10cks/next/client'

export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <B10cksNextProvider
      apiClientOptions={{
        token: process.env.NEXT_PUBLIC_B10CKS_TOKEN || '',
        baseUrl: 'https://api.b10cks.com/api',
      }}
      scrollOffset={80}
      allowedOrigins={['https://app.b10cks.com']}
    >
      {children}
    </B10cksNextProvider>
  )
}
```

## Live preview & visual editing

`@b10cks/next/client` re-exports the editing hooks from `@b10cks/react` — `useEditable`, `useEditableField`, `usePreviewContent`, and `usePreviewSelection`. They're no-ops outside the editor:

```tsx
'use client'

import { usePreviewContent } from '@b10cks/next/client'

export function PageRenderer({ initialContent }: { initialContent: PageContent }) {
  const content = usePreviewContent(initialContent)
  return <BlockRenderer block={content} />
}
```

Server components fetch the content; a thin client component wraps it in `usePreviewContent` so the visual editor can live-update the tree. See the [React guide](react.md#live-preview--visual-editing) for the full hook reference, and [Live preview & visual editing](live-preview.md) for how the bridge works.

For draft preview, forward the editor's `b10cks_vid` query parameter as `vid` when fetching:

```tsx
const content = await dataApi.getContent(slug, {
  vid: searchParams.b10cks_vid || 'published',
})
```

## Rich text

`B10cksRichText` works in both server and client components (it renders to static HTML with no client dependencies):

```tsx
import { B10cksRichText } from '@b10cks/next'

<B10cksRichText document={block.body} className="prose" />
```

See [Rendering rich text](rich-text.md) for options.

## SEO metadata

Give your root block a **Meta** field and map it in `generateMetadata` — editors then control titles, descriptions, and OG previews per page without code changes:

```tsx
// app/[[...slug]]/page.tsx
import type { Metadata } from 'next'
import { getB10cks } from '@/lib/b10cks'
import { ilum } from '@/lib/ilum'

export async function generateMetadata({ params }: { params: { slug?: string[] } }): Promise<Metadata> {
  const { dataApi } = getB10cks()
  const content = await dataApi.getContent(params.slug?.join('/') || 'home')
  const meta = content.content.meta

  return {
    title: meta?.title || content.name,
    description: meta?.description,
    openGraph: {
      title: meta?.ogTitle || meta?.title || content.name,
      description: meta?.ogDescription || meta?.description,
      images: meta?.ogImage ? [ilum(meta.ogImage.full_path, 'w_1200,h_630,c_fill')] : undefined,
    },
    robots: meta?.robots,
  }
}
```

`getB10cks` is wrapped in React's `cache()`, so the `getContent` call here and the one in your page component share one request within the same render — no double fetch.

## Images

Asset fields deliver a `full_path` the built-in image service (Ilum) transforms on the fly. Wire it into `next/image` with a custom loader:

```ts
// lib/ilum.ts
import type { ImageLoader } from 'next/image'

export function ilum(fullPath: string, ops: string) {
  return `${process.env.NEXT_PUBLIC_ILUM_BASE_URL}/${fullPath}/${ops}`
}

export const ilumLoader: ImageLoader = ({ src, width, quality }) =>
  `${process.env.NEXT_PUBLIC_ILUM_BASE_URL}/${src}/w_${width}?quality=${quality || 80}&format=webp`
```

```tsx
import Image from 'next/image'
import { ilumLoader } from '@/lib/ilum'

<Image
  loader={ilumLoader}
  src={block.image.full_path}
  alt={block.image.alt}
  width={800}
  height={450}
  sizes="100vw"
/>
```

Ilum also supports cropping, gravity/focal points, and format conversion — see [Image service (Ilum)](../concepts/image-service.md) for the full operation syntax. Allow the Ilum host in `next.config.ts` `images.remotePatterns` if you also use plain `<Image>` with absolute URLs.

## Redirects

Redirects managed in the admin UI are served by the Data API. Apply them in `middleware.ts` with the single-lookup endpoint (no need to ship the whole rule list to the edge):

```ts
// middleware.ts
import { NextResponse, type NextRequest } from 'next/server'
import { createB10cksNextApi } from '@b10cks/next/server'

export async function middleware(request: NextRequest) {
  // Editor preview URLs must never be redirected away
  if (request.nextUrl.searchParams.has('b10cks_vid')) return NextResponse.next()

  const { dataApi } = createB10cksNextApi({
    token: process.env.B10CKS_TOKEN || '',
    baseUrl: process.env.B10CKS_API_URL || 'https://api.b10cks.com/api',
    fetchClient: fetch,
  })

  const redirect = await dataApi.lookupRedirect(request.nextUrl.pathname)
  if (redirect) {
    return NextResponse.redirect(new URL(redirect.target, request.url), redirect.status_code)
  }

  return NextResponse.next()
}

export const config = {
  matcher: ['/((?!_next|api|.*\\..*).*)'],
}
```

On very high-traffic sites, prefer fetching the full map once with `dataApi.getRedirects()` (cached client-side) and consulting it locally.

## Sitemap

The Data API's sitemap endpoint returns the published tree's slugs and timestamps — everything `app/sitemap.ts` needs:

```ts
// app/sitemap.ts
import type { MetadataRoute } from 'next'
import { createB10cksNextApi } from '@b10cks/next/server'

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const { dataApi } = createB10cksNextApi({
    token: process.env.B10CKS_TOKEN || '',
    baseUrl: process.env.B10CKS_API_URL || 'https://api.b10cks.com/api',
    fetchClient: fetch,
  })

  const entries = await dataApi.getSitemap()

  return entries.map((entry) => ({
    url: `https://example.com/${entry.full_slug === 'home' ? '' : entry.full_slug}`,
    lastModified: entry.published_at,
  }))
}
```

## Production checklist

- **Caching**: Data API responses are revision-stamped and CDN-friendly by design; combine with Next's fetch caching or ISR (`revalidate`) as suits your traffic. Publishing content changes the space revision, so revalidated fetches pick up changes naturally.
- **Environments**: separate tokens per environment (production, staging, preview) so each can be revoked independently; `noindex` staging via `robots`.
- **CSP**: allow `connect-src` for the API origin, `img-src` for the Ilum host, and `frame-ancestors` for the editor origin (`https://app.b10cks.com` or your self-hosted admin UI).
- **Rebuild on publish**: for fully static output, create an [automation](../concepts/automations.md) that calls your deploy hook when content is published.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Draft content leaks to visitors | A module-level `createB10cksNextApi()` singleton — its pinned revision/caches are request-scoped state. Use `defineB10cksNextApi` (see above). |
| 401 from the API | Missing/invalid token, or the space is not live. |
| Draft changes not visible in preview | The page ignores the editor's `b10cks_vid` query parameter — forward it as `vid`. |
| Live editing doesn't update the page | The tree is rendered purely server-side — wrap it in a client component using `usePreviewContent`. |
| Editor can't embed the site | `frame-ancestors` in your CSP (or `X-Frame-Options`) blocks the editor origin. |
