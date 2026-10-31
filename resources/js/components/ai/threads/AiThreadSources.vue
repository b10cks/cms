<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

import Icon from '~/components/Icon.vue'
import type { AiSource } from '~/lib/aiRun'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'
import type { AiThreadSource } from '~/lib/aiThreads'

/**
 * The knowledge a turn read, as one quiet line like the work log: "3 sources",
 * opening into document titles with their heading. Stored sources link to
 * their document; live ones and system documents don't.
 */
const props = defineProps<{
  spaceId: string
  sources: readonly (AiSource | AiThreadSource)[]
}>()

const open = ref(false)

const linkFor = (source: AiSource | AiThreadSource): RouteLocationRaw | null => {
  if (!('scope' in source)) return null
  if (source.scope === 'space') return aiSettingsRoutes({ kind: 'space', id: props.spaceId }).document(source.document_id)
  if (source.scope === 'team' && source.team_id) {
    return aiSettingsRoutes({ kind: 'team', id: source.team_id }).document(source.document_id)
  }
  return null
}

const rows = computed(() =>
  props.sources.map((source) => ({
    key: `${source.document_id}:${source.heading ?? ''}`,
    title: source.title ?? source.document_id,
    heading: source.heading,
    to: linkFor(source),
  }))
)
</script>

<template>
  <div
    v-if="sources.length > 0"
    class="grid gap-1.5 text-sm"
  >
    <button
      type="button"
      class="inline-flex w-fit items-center gap-1 rounded-md text-muted transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      :aria-expanded="open"
      @click="open = !open"
    >
      {{ $t('components.aiThreads.sources', sources.length) }}
      <Icon
        name="lucide:chevron-right"
        class="size-3.5 transition-transform"
        :class="open && 'rotate-90'"
      />
    </button>

    <ul
      v-if="open"
      class="ml-1.5 grid gap-1 border-l border-border pl-3"
    >
      <li
        v-for="row in rows"
        :key="row.key"
        class="flex min-w-0 items-center gap-2 text-muted"
      >
        <Icon
          name="lucide:book-open"
          class="shrink-0"
        />
        <component
          :is="row.to ? 'RouterLink' : 'span'"
          :to="row.to ?? undefined"
          class="truncate"
          :class="row.to && 'text-foreground hover:underline'"
        >
          {{ row.title }}<template v-if="row.heading">, {{ row.heading }}</template>
        </component>
      </li>
    </ul>
  </div>
</template>
