<script setup lang="ts">
import AiChangesetItem from '~/components/ai/AiChangesetItem.vue'
import Icon from '~/components/Icon.vue'
import type { AiChangesetEntry, AiChangesetItem as Item } from '~/lib/aiChangesets'
import { isOpenItem } from '~/lib/aiChangesets'

/**
 * One entry of a changeset with its proposed fields. As a `card` it stands
 * alone in the thread; as a `row` it sits collapsed in a summary card.
 */
const props = defineProps<{
  spaceId: string
  entry: AiChangesetEntry
  busy: boolean
  variant: 'card' | 'row'
  /** Language names of a cross-locale item, null for same-locale edits */
  locales: (item: Item) => { source: string; target: string } | null
}>()

/** Reviewer edits of text proposals, item ID => text */
const edits = defineModel<Record<string, string | undefined>>('edits', { required: true })

const emit = defineEmits<{
  accept: [itemIds: string[], overwrite: boolean]
  reject: [itemIds: string[]]
}>()

const expanded = ref(props.variant === 'card')
const openCount = computed(() => props.entry.items.filter(isOpenItem).length)
const link = computed(() =>
  props.entry.targetType === 'content' && props.entry.targetId
    ? `/${props.spaceId}/content/${props.entry.targetId}`
    : null
)
</script>

<template>
  <section
    :class="
      variant === 'card'
        ? '@container rounded-xl border border-border bg-card text-card-foreground shadow-soft-sm'
        : '@container'
    "
    :data-entry="entry.key"
  >
    <header class="flex min-h-11 items-center gap-2 px-4 py-2">
      <button
        v-if="variant === 'row'"
        type="button"
        class="-ml-1 flex size-7 shrink-0 items-center justify-center rounded-md text-muted hover:bg-input focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
        :aria-expanded="expanded"
        :aria-label="expanded ? $t('components.aiThreads.collapseEntry', { name: entry.label }) : $t('components.aiThreads.expandEntry', { name: entry.label })"
        @click="expanded = !expanded"
      >
        <Icon
          name="lucide:chevron-right"
          :class="['transition-transform duration-200', expanded && 'rotate-90']"
        />
      </button>
      <h3 class="min-w-0 truncate font-semibold text-primary">
        <RouterLink
          v-if="link"
          :to="link"
          class="hover:underline"
        >
          {{ entry.label }}
        </RouterLink>
        <span v-else>{{ entry.label }}</span>
      </h3>
      <span class="ml-auto shrink-0 text-sm text-muted">
        {{
          openCount > 0
            ? $t('components.aiThreads.openFields', { count: openCount })
            : $t(`components.aiChangeset.targets.${entry.targetType}`)
        }}
      </span>
    </header>

    <ul
      v-if="expanded"
      class="divide-y divide-border border-t border-border"
    >
      <li
        v-for="item in entry.items"
        :key="item.id"
        class="px-4 py-3"
        :data-item="item.id"
      >
        <AiChangesetItem
          v-model:edit="edits[item.id]"
          :item="item"
          :busy="busy"
          :locales="locales(item)"
          @accept="(overwrite) => emit('accept', [item.id], overwrite)"
          @reject="emit('reject', [item.id])"
        />
      </li>
    </ul>
  </section>
</template>
