---
description: "Integrate b10cks into Svelte and SvelteKit with @b10cks/svelte: stores, actions, server-side loading, and live preview."
---

# Svelte Guide

Integrate b10cks into a Svelte or SvelteKit app with [`@b10cks/svelte`](https://www.npmjs.com/package/@b10cks/svelte).

## Install

```bash
bun add @b10cks/svelte @b10cks/client @b10cks/richtext
```

## Set up the context

Call `createB10cksContext` in a root component (e.g. your layout), then create stores anywhere below it:

```svelte
<script lang="ts">
  import { createB10cksContext, createB10cksStores } from '@b10cks/svelte'

  createB10cksContext({
    apiClientOptions: {
      token: import.meta.env.VITE_B10CKS_TOKEN,
      baseUrl: 'https://api.b10cks.com/api', // or your self-hosted /api URL
    },
    // Optional — live preview inside the visual editor:
    scrollOffset: 80,
    allowedOrigins: ['https://app.b10cks.com'],
  })

  const { useContent, useContents } = createB10cksStores()
  const page = useContent('home', { vid: 'published' })
</script>

{#if $page.pending}
  <p>Loading…</p>
{:else if $page.data}
  <h1>{$page.data.name}</h1>
{/if}
```

The stores are typed async stores exposing `{ data, pending, error }`. List stores accept the same parameters as the Data API — see [Querying content](querying-content.md).

## Map blocks to components

`<B10cksComponent>` renders the component registered for a block's technical name — it matches the exact slug (`hero_banner`), its PascalCase form (`HeroBanner`), or lowercase, in that order. Unmatched blocks render a fallback (override it with the `fallback` prop):

```svelte
<script lang="ts">
  import { B10cksComponent } from '@b10cks/svelte'
  import Hero from './blocks/Hero.svelte'
  import Teaser from './blocks/Teaser.svelte'

  export let block

  const components = { hero: Hero, teaser: Teaser }
</script>

<main>
  {#each block.body as b (b.id)}
    <B10cksComponent block={b} {components} />
  {/each}
</main>
```

> **Typed blocks:** generate TypeScript interfaces for all your block schemas with `b10cks generate-types <spaceId>` — see the [CLI guide](cli.md#generate-types).

## SvelteKit: server-side fetching & routing

In SvelteKit, fetch on the server with the framework-agnostic client (`@b10cks/client`) in a catch-all route's `load` function, mapping the URL path to a content slug:

```ts
// src/routes/[...slug]/+page.server.ts
import { error } from '@sveltejs/kit'
import { ApiClient, createB10cksDataApi } from '@b10cks/client'
import { B10CKS_TOKEN } from '$env/static/private'

export async function load({ params, url, fetch }) {
  const client = new ApiClient({
    baseUrl: 'https://api.b10cks.com/api', // or your self-hosted /api URL
    token: B10CKS_TOKEN,
    fetchClient: fetch,
  })
  const dataApi = createB10cksDataApi(client)

  try {
    // Honoring b10cks_vid lets the visual editor preview drafts through your real routes
    const content = await dataApi.getContent(params.slug || 'home', {
      vid: (url.searchParams.get('b10cks_vid') as 'draft' | 'published') || 'published',
    })
    return { content }
  } catch (e) {
    throw error((e as { status?: number }).status === 404 ? 404 : 500, 'Content not found')
  }
}
```

```svelte
<!-- src/routes/[...slug]/+page.svelte -->
<script lang="ts">
  import { B10cksComponent, createPreviewContent } from '@b10cks/svelte'
  import { page } from '$app/stores'
  import { derived } from 'svelte/store'
  import { components } from '$lib/components'

  const content = createPreviewContent(
    derived(page, ($p) => ({ id: $p.data.content.id, block: $p.data.content.block, ...$p.data.content.content }))
  )
</script>

<B10cksComponent block={$content} {components} />
```

See the [JavaScript guide](javascript.md) for the full client API.

## Live preview & visual editing

The `editable` and `editableField` actions make blocks selectable and editable inside the b10cks visual editor. They are no-ops outside the editor:

```svelte
<script lang="ts">
  import { editable, editableField, createPreviewContent } from '@b10cks/svelte'

  export let block
  // Whole-tree live updates — including nested blocks and rich text:
  const content = createPreviewContent(block)
</script>

<!-- Selectable block -->
<section use:editable={$content}>
  <!-- Inline-edit a simple string field -->
  <h1 use:editableField={{ id: $content.id, field: 'headline' }}>{$content.headline}</h1>

  <!-- Complex fields: deep-select so the editor opens its own field editor.
       Actions apply to DOM elements, so wrap components in a container. -->
  <div use:editableField={{ id: $content.id, path: ['body'], mode: 'select' }}>
    <B10cksRichText document={$content.body} />
  </div>
</section>
```

`createPreviewContent` accepts a plain value or a readable store. Pass a store when the content refetches on navigation, so the preview resets instead of keeping a stale tree:

```svelte
<script lang="ts">
  import { createPreviewContent } from '@b10cks/svelte'
  import { page } from '$app/stores'
  import { derived } from 'svelte/store'

  const content = createPreviewContent(derived(page, ($p) => $p.data.content))
</script>
```

## Rich text

```svelte
<script lang="ts">
  import { B10cksRichText } from '@b10cks/svelte'
</script>

<B10cksRichText document={block.body} class="prose" />
```

For rendering to strings (HTML or plain text), use `@b10cks/richtext` directly — see [Rendering rich text](rich-text.md).

## Images

Asset fields deliver a `full_path` the built-in image service (Ilum) transforms on the fly — append URL operations for resizing, cropping, and format conversion:

```svelte
<script lang="ts">
  import { PUBLIC_ILUM_BASE_URL } from '$env/static/public'

  const ilum = (fullPath: string, ops: string) => `${PUBLIC_ILUM_BASE_URL}/${fullPath}/${ops}`
</script>

<img
  src={ilum(block.image.full_path, 'w_1600,h_900,c_fill,g_auto')}
  srcset={[800, 1200, 1600].map((w) => `${ilum(block.image.full_path, `w_${w}`)} ${w}w`).join(', ')}
  sizes="100vw"
  alt={block.image.alt}
/>
```

Full operation syntax (crop modes, gravity, focal points, `format`/`quality`) in [Image service (Ilum)](../concepts/image-service.md).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Stores throw about a missing context | `createB10cksStores()` called outside a component tree where `createB10cksContext()` ran (it must be a parent). |
| 401 from the API | Missing/invalid token, or the space is not live. |
| A block renders the fallback | No entry for its slug (or PascalCase name) in the `components` map. |
| Draft changes not visible in preview | Your route ignores the `b10cks_vid` query parameter — pass it through as `vid`. |
| Blocks don't highlight in the visual editor | Missing `use:editable` on the block's root element, or the editor origin is blocked by `allowedOrigins`/CSP `frame-ancestors`. |
| Edits don't update after navigation | `createPreviewContent` got a plain value — pass a store so the preview resets on refetch. |
