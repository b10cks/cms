---
description: "Integrate b10cks into a Vue 3 app with @b10cks/vue: plugin setup, data composables, block components, and live preview."
---

# Vue Guide

Integrate b10cks into a plain Vue 3 app with [`@b10cks/vue`](https://www.npmjs.com/package/@b10cks/vue). If you use Nuxt, follow the [Nuxt guide](nuxt.md) instead — the Nuxt module builds on this package and adds SSR-aware data fetching and component auto-registration.

## Install

```bash
bun add @b10cks/vue @b10cks/client @b10cks/richtext
```

Register the plugin:

```ts
import { createApp } from 'vue'
import { B10cksVue } from '@b10cks/vue'
import App from './App.vue'

const app = createApp(App)

app.use(B10cksVue, {
  apiClientOptions: {
    token: import.meta.env.VITE_B10CKS_TOKEN,
    baseUrl: 'https://api.b10cks.com/api', // or your self-hosted /api URL
  },
  // Optional — live preview inside the visual editor:
  scrollOffset: 80,
  allowedOrigins: ['https://app.b10cks.com'],
})

app.mount('#app')
```

The plugin registers the `B10cksComponent` component and the `v-editable` / `v-editable-field` directives globally.

## Fetch content

`useB10cksApi()` exposes reactive data composables:

```ts
import { useB10cksApi } from '@b10cks/vue'

const { useContent, useContents, useBlocks } = useB10cksApi()

// Single entry by slug
const page = useContent('home', { vid: 'published' }, { immediate: true })

// A filtered list
const { data: articles } = useContents(
  {
    language_iso: 'en',
    vid: 'published',
    sort: '-published_at',
    filter: { content_type: 'article' },
  },
  { immediate: true }
)
```

Each composable returns `{ data, pending, error }` refs plus a trigger function. `immediate: true` fires the request on setup; pass `immediate: false` when the request depends on reactive state that isn't ready yet, and trigger it manually.

See [Querying content](querying-content.md) for filters, sorting, and pagination.

## Render blocks

`<B10cksComponent>` renders the component matching a block's technical name (converted to PascalCase — a `hero_banner` block renders `HeroBanner`). Register your block components globally, or provide a custom resolver for lazy loading:

```ts
import { B10cksComponentResolverKey } from '@b10cks/vue'

app.provide(B10cksComponentResolverKey, (name: string) => {
  return import(`./b10cks/${name}.vue`).then((m) => m.default)
})
```

```vue
<B10cksComponent
  v-if="page.data.value"
  :block="{ id: page.data.value.id, block: page.data.value.block, ...page.data.value.content }"
  :content="page.data.value"
/>
```

Unresolvable blocks render a fallback and log a console warning with the expected component name.

> **Typed blocks:** generate TypeScript interfaces for all your block schemas with `b10cks generate-types <spaceId>` — see the [CLI guide](cli.md#generate-types).

### Routing

The canonical setup is one catch-all route that maps the URL path to a content slug, mirroring the content tree:

```ts
// router.ts
const router = createRouter({
  history: createWebHistory(),
  routes: [{ path: '/:slug(.*)*', component: () => import('./ContentPage.vue') }],
})
```

```vue
<!-- ContentPage.vue -->
<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useB10cksApi } from '@b10cks/vue'

const route = useRoute()
const slug = computed(() =>
  (Array.isArray(route.params.slug) ? route.params.slug.join('/') : route.params.slug) || 'home'
)

const { useContent } = useB10cksApi()
const page = useContent(slug.value, {
  // Honoring b10cks_vid lets the visual editor preview drafts through your real routes
  vid: (route.query.b10cks_vid as 'draft' | 'published') || 'published',
}, { immediate: true })
</script>
```

## Live preview & visual editing

Inside the b10cks visual editor, the directives make blocks selectable and editable in place. They are no-ops outside the editor:

```vue
<!-- Selectable block: click selects it in the editor -->
<section v-editable="block">
  <!-- Inline-edit a simple string field -->
  <h1 v-editable-field="{ id: block.id, field: 'headline' }">{{ block.headline }}</h1>

  <!-- Complex fields: deep-select so the editor opens its own field editor -->
  <B10cksRichText
    :document="block.body"
    v-editable-field="{ id: block.id, path: ['body'], mode: 'select' }"
  />
</section>
```

For whole-tree live updates (nested blocks, rich text), wrap the fetched content in `usePreviewContent`:

```vue
<script setup lang="ts">
import { usePreviewContent } from '@b10cks/vue'

const { data } = useContent('home', {}, { immediate: true })
const content = usePreviewContent(() => data.value?.content)
</script>

<template>
  <B10cksComponent v-if="content" :block="content" />
</template>
```

Pass a getter or ref (not a plain value) so the preview resets when the content is refetched on navigation. More background in [Live preview & visual editing](live-preview.md).

## Rich text

Rich text lives in a sub-path export so it stays out of bundles that don't need it:

```vue
<script setup lang="ts">
import { B10cksRichText } from '@b10cks/vue/rich-text'
</script>

<template>
  <B10cksRichText
    :document="block.body"
    class="prose"
    :options="{ internalLinkHandler: (attrs) => resolveUrl(attrs.content) }"
  />
</template>
```

See [Rendering rich text](rich-text.md) for internal links, placeholders, and URL safety.

## Images

Asset fields deliver a `full_path` the built-in image service (Ilum) transforms on the fly — append URL operations for resizing, cropping, and format conversion:

```vue
<script setup lang="ts">
function ilum(fullPath: string, ops: string) {
  return `${import.meta.env.VITE_ILUM_BASE_URL}/${fullPath}/${ops}`
}
</script>

<template>
  <img
    :src="ilum(block.image.full_path, 'w_1600,h_900,c_fill,g_auto')"
    :srcset="[800, 1200, 1600].map((w) => `${ilum(block.image.full_path, `w_${w}`)} ${w}w`).join(', ')"
    sizes="100vw"
    :alt="block.image.alt"
  />
</template>
```

Transformed URLs are immutable per asset version and cache indefinitely. Full operation syntax (crop modes, gravity, focal points, `format`/`quality`) in [Image service (Ilum)](../concepts/image-service.md).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Composables throw about a missing client | `useB10cksApi()` used outside a component tree with the `B10cksVue` plugin installed. |
| 401 from the API | Missing/invalid token, or the space is not live. |
| A block renders the fallback | No globally registered component (or resolver result) matching the block's PascalCase name. |
| Request never fires | The composable was created with `immediate: false` (the default for lists) — pass `immediate: true` or call the trigger function. |
| Draft changes not visible in preview | Your route ignores the `b10cks_vid` query parameter — pass it through as `vid`. |
| Edits don't update nested blocks live | Wrap the fetched tree in `usePreviewContent(() => data.value?.content)`. |
