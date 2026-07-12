---
description: "Build a Nuxt 4 site on b10cks with @b10cks/nuxt: dynamic routing, live preview, optimized images, SEO, redirects, and sitemap."
---

# Nuxt Guide

Build a Nuxt 4 site on top of b10cks using the official [`@b10cks/nuxt`](https://www.npmjs.com/package/@b10cks/nuxt) module. This guide takes you from an empty project to a production-ready site with dynamic routing, live preview, optimized images, SEO, and redirects.

If you want a working starting point instead of building up from scratch, use the [Nuxt boilerplate](https://github.com/b10cks/nuxt-boilerplate) — it implements everything described here.

## Prerequisites

- A b10cks space with at least one **root block** (e.g. `page`) and one published content entry (e.g. `home`). See the [Quickstart](../getting-started/quickstart.md) if you don't have one yet.
- A Data API **access token** for the space: **Settings → Access tokens** in the admin UI.
- Node 20+ and a package manager (examples use `bun`; `npm`/`pnpm`/`yarn` work the same).

## 1. Install

```bash
bun add @b10cks/nuxt @b10cks/vue @b10cks/client @b10cks/richtext
```

Register the module and configure it in `nuxt.config.ts`:

```ts
export default defineNuxtConfig({
  modules: ['@b10cks/nuxt'],

  b10cks: {
    accessToken: process.env.NUXT_B10CKS_ACCESS_TOKEN || '',
    apiUrl: process.env.NUXT_B10CKS_API_URL || 'https://api.b10cks.com/api',
    componentsDir: '~/b10cks',
  },
})
```

| Option | Default | Description |
| --- | --- | --- |
| `accessToken` | — | Data API token of your space (sent as the `token` query parameter). |
| `apiUrl` | `https://api.b10cks.com/api` | API base URL. For self-hosted instances, point this at your installation, e.g. `https://cms.example.com/api`. |
| `componentsDir` | `~/b10cks` | Directory of your block components. They are registered globally and matched by block name. |
| `scrollOffset` | — | Offset (number → px, or a string like `'5rem'`) used when the visual editor scrolls a selected block into view, so selection clears a fixed header. Can also be set purely in CSS: `:root { --b10cks-scroll-offset: 80px }`. |
| `allowedOrigins` | — | Restricts the preview-bridge handshake to known editor origins, e.g. `['https://app.b10cks.com']`. |

Keep the token out of the repo:

```bash
# .env
NUXT_B10CKS_ACCESS_TOKEN=your-access-token
NUXT_B10CKS_API_URL=https://api.b10cks.com/api
```

> **Note:** a Data API token only grants read access to published/draft content of one space, but treat it like any credential — use different tokens per environment so you can revoke them independently.

## 2. Map blocks to components

Every content entry in b10cks is a tree of blocks. `<B10cksComponent>` renders the Vue component that matches a block's technical name: the block slug is converted to PascalCase and resolved from your `componentsDir`. A `hero_banner` block renders `~/b10cks/HeroBanner.vue`; a `page` block renders `~/b10cks/Page.vue`. If no component matches, a fallback is rendered and a warning is logged with the missing name.

Each block component receives the block's fields as `block`, plus the surrounding content entry as `content` on root components:

```vue
<!-- ~/b10cks/Teaser.vue -->
<script setup lang="ts">
const props = defineProps<{
  block: {
    id: string
    block: 'teaser'
    headline: string
    text: string
  }
}>()
</script>

<template>
  <section v-editable="block">
    <h2 v-editable-field="{ id: block.id, field: 'headline' }">{{ block.headline }}</h2>
    <p>{{ block.text }}</p>
  </section>
</template>
```

The root component of a content type typically iterates its nested `blocks` field and renders children with `<B10cksComponent>` again:

```vue
<!-- ~/b10cks/Page.vue -->
<script setup lang="ts">
import type { IBContent } from '@b10cks/client'

const props = defineProps<{ block: B10cksPage; content: IBContent }>()
defineOptions({ inheritAttrs: false })
</script>

<template>
  <main v-editable="block">
    <B10cksComponent
      v-for="(b, i) in block.body"
      :key="b.id"
      :block="b"
      :is-first="i === 0"
    />
  </main>
</template>
```

Extra props like `:is-first` pass straight through to the resolved component — useful for things like eager-loading only the first image on a page.

## 3. Fetch and render content

The module exposes all data composables through `useB10cksApi()`. Each one wraps Nuxt's `useAsyncData()`, so requests run on the server, serialize into the SSR payload, and are not refetched during hydration. Stable async-data keys are derived from the inputs — no manual `key` needed.

The canonical pattern is a single catch-all route that maps the URL path to a content slug:

```vue
<!-- ~/pages/[...slug].vue -->
<script setup lang="ts">
const route = useRoute()
const slug = Array.isArray(route.params.slug)
  ? route.params.slug.join('/')
  : route.params.slug || 'home'

const { useContent } = useB10cksApi()
const { data: content, error } = await useContent(slug, {
  vid: (route.query.b10cks_vid as string) || 'published',
})

if (error.value) {
  throw error.value
}
</script>

<template>
  <B10cksComponent
    v-if="content"
    :block="{ id: content.id, block: content.block, ...content.content }"
    :content="content"
  />
</template>
```

Two details worth noting:

- **`vid`** selects the version to deliver: `'published'` (default for live traffic) or `'draft'`. Honoring the `b10cks_vid` query parameter is what lets the visual editor preview unpublished drafts through your real frontend.
- A missing entry rejects with an error carrying the API status; `throw error.value` lets Nuxt render its 404/error page.

### Fetching lists

`useContents()` accepts the same query parameters as the Data API's content collection endpoint, including a typed `filter` object:

```ts
const { useContents } = useB10cksApi()

const { data: articles } = await useContents({
  language_iso: 'en',
  vid: 'published',
  parent_id: articlesFolderId,
  per_page: 12,
  sort: '-published_at',
  filter: {
    canonical_id: { in: [idA, idB] },
  },
})
```

See [Querying content](querying-content.md) for the full filter, sort, and pagination reference.

### Site-wide configuration content

A common pattern is a singleton `config` content entry holding header navigation, footer links, and social profiles. Fetch it once with `useB10cksConfig()` and provide it to your layout:

```ts
const { useB10cksConfig } = useB10cksApi()
const { data: config } = await useB10cksConfig()
```

## 4. Live preview & visual editing

When your site runs inside the b10cks visual editor, the SDK's preview bridge connects the iframe to the editor. All of the following are no-ops outside the editor, so they're safe to ship to production.

1. **Make blocks selectable** with `v-editable` — clicking the element selects the block in the editor, and the editor highlights and scrolls to it:

   ```vue
   <section v-editable="block">…</section>
   ```

2. **Enable inline editing** of simple string fields with `v-editable-field`:

   ```vue
   <h1 v-editable-field="{ id: block.id, field: 'headline' }">{{ block.headline }}</h1>
   ```

   For rich text and other complex fields, use select mode so the editor opens its own field editor:

   ```vue
   <B10cksRichText
     :document="block.body"
     v-editable-field="{ id: block.id, path: ['body'], mode: 'select' }"
   />
   ```

3. **Keep the whole tree live** with `usePreviewContent` (auto-imported). `v-editable` patches single blocks in place; `usePreviewContent` makes the entire content tree react to editor changes, including nested blocks and rich text:

   ```vue
   <script setup lang="ts">
   const { useContent } = useB10cksApi()
   const { data } = await useContent(slug, { vid })

   // Pass a getter so the preview resets when content is refetched
   // (route change, locale switch) instead of keeping the first tree.
   const content = usePreviewContent(() => data.value.content)
   </script>

   <template>
     <B10cksComponent :block="content" />
   </template>
   ```

4. **Allow the editor to embed your site.** If you send a `Content-Security-Policy`, include the editor origin in `frame-ancestors`:

   ```
   Content-Security-Policy: … frame-ancestors https://app.b10cks.com;
   ```

   (For self-hosted instances, use your admin UI origin.)

5. In the space's **visual editor settings**, point the preview URL at your site (locally, e.g. `https://localhost:3000/`). The editor appends `b10cks_vid` so your catch-all route serves drafts — which is exactly what step 3 above wired up.

See [Live preview & visual editing](live-preview.md) for how the bridge works under the hood.

## 5. Images with `@nuxt/image` and Ilum

b10cks ships an on-the-fly image transformation service (Ilum) that resizes, crops, and re-encodes assets via URL operations. The boilerplate wires it into `@nuxt/image` as a custom provider, giving you `<NuxtImg>`/`<NuxtPicture>` with responsive `srcset` for free.

Copy [`app/utils/providers/ilum.ts` from the boilerplate](https://github.com/b10cks/nuxt-boilerplate/blob/main/app/utils/providers/ilum.ts), then register it:

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@b10cks/nuxt', '@nuxt/image'],

  image: {
    provider: 'ilum',
    providers: {
      ilum: {
        name: 'ilum',
        provider: '~/utils/providers/ilum',
        options: { baseURL: process.env.NUXT_ILUM_BASE_URL },
      },
    },
  },
})
```

Asset fields deliver a `full_path` you can hand straight to the provider:

```vue
<NuxtImg
  :src="block.image.full_path"
  :alt="block.image.alt"
  width="800"
  height="450"
  sizes="100vw md:50vw"
  format="webp"
/>
```

The provider supports `width`/`height`, crop modes (`fill`, `fit`, `crop`), gravity (`face`, `center`, `auto`, or a focal point), and `format`/`quality`. See [Image service (Ilum)](../concepts/image-service.md) for the full URL syntax.

## 6. SEO metadata

Give your root block a `meta` field (the **Meta** field type, with OG tags enabled) and map it to `useSeoMeta` in the root component:

```ts
const $img = useImage()
const imgUrl = props.block.meta?.ogImage
  ? $img(props.block.meta.ogImage.full_path, { width: 1200, height: 630 }, { provider: 'ilum' })
  : undefined

useSeoMeta({
  title: () => props.block.meta?.title || props.content.name,
  description: () => props.block.meta?.description,
  ogTitle: () => props.block.meta?.ogTitle || props.block.meta?.title || props.content.name,
  ogDescription: () => props.block.meta?.ogDescription || props.block.meta?.description,
  ogImage: () => imgUrl,
  robots: () => props.block.meta?.robots,
})
```

Editors then control titles, descriptions, OG previews, and robots directives per page — no code changes needed.

## 7. Redirects

Redirects managed in the admin UI are served by the Data API and can be applied in a global route middleware, so they work for client-side navigation too:

```ts
// ~/middleware/redirects.global.ts
export default defineNuxtRouteMiddleware(async (to) => {
  const { useRedirects } = useB10cksApi()
  const { data: redirects } = await useRedirects()

  const redirect = redirects.value?.[to.path]
  if (redirect && !to.query.b10cks_vid) {
    return navigateTo(redirect.target, { statusCode: redirect.status_code })
  }
})
```

`useRedirects()` returns a map of `source → { target, status_code }`. The `b10cks_vid` guard keeps editor preview URLs from being redirected away.

## 8. Rich text

The module makes `<B10cksRichText>` available for rendering rich text fields (ProseMirror JSON) as HTML, SSR-safe and dependency-free:

```vue
<B10cksRichText
  :document="block.body"
  :internal-link-handler="(attrs) => resolveContentUrl(attrs.content)"
/>
```

Internal links in rich text store the target content's ID; `internalLinkHandler` turns that into a URL for your routing scheme. See [Rendering rich text](rich-text.md) for placeholders, custom classes, and plain-text rendering.

## 9. Sitemap

The Data API exposes a dedicated sitemap endpoint with the published tree's slugs and timestamps. Generate `sitemap.xml` in a server route:

```ts
// ~/server/routes/sitemap.xml.ts — sketch
import { ApiClient, createB10cksDataApi } from '@b10cks/client'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const client = new ApiClient({
    baseUrl: config.public.b10cks.apiUrl,
    token: config.b10cksToken,
    fetchClient: fetch,
  })
  const entries = await createB10cksDataApi(client).getSitemap()

  // map entries to <urlset> XML …
})
```

## 10. Production checklist

- **Cache headers**: Data API responses are cached and revision-stamped server-side; add CDN-friendly `Cache-Control` route rules for your pages (see the boilerplate's `routeRules`).
- **Preconnect** to your API origin: `{ rel: 'preconnect', href: 'https://api.b10cks.com' }`.
- **CSP**: allow `connect-src`/`media-src` for the API origin, `img-src` for the image service, and `frame-ancestors` for the editor origin.
- **Environments**: use separate tokens (and `NUXT_PUBLIC_APP_ENV`) for staging vs. production; send `X-Robots-Tag: noindex` on staging.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `Component "X" not found` warning and fallback rendering | No component named after the block (PascalCase) in `componentsDir`. Create `~/b10cks/X.vue`. |
| 401 from the API | Missing/invalid token, or the space is not live. |
| Draft changes not visible in preview | Your route ignores the `b10cks_vid` query parameter — pass it through as `vid`. |
| Blocks don't highlight in the visual editor | Missing `v-editable` on the block's root element, or the editor origin is blocked by `allowedOrigins`/CSP `frame-ancestors`. |
| Edits don't update nested blocks live | Wrap the fetched tree in `usePreviewContent(() => data.value.content)`. |
