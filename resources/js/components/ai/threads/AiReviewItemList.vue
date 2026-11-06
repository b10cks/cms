<script setup lang="ts">
import AiChangesetItem from '~/components/ai/AiChangesetItem.vue'
import type { AiChangesetItem as Item } from '~/lib/aiChangesets'
import { entryLink } from '~/lib/aiChangesets'

/**
 * Proposed changes of a review group, each under the name of the entry it
 * changes, decided one by one.
 */
const props = defineProps<{
  spaceId: string
  items: readonly Item[]
  busy: boolean
  /** Language names of a cross-locale item, null for same-locale edits */
  locales: (item: Item) => { source: string; target: string } | null
  /** The review group's key */
  scope: string
}>()

const emit = defineEmits<{
  accept: [item: Item, overwrite: boolean]
  reject: [item: Item]
}>()

const link = (item: Item) => entryLink(props.spaceId, { targetType: item.target_type, targetId: item.target_id })
</script>

<template>
  <ul class="@container divide-y divide-border">
    <li
      v-for="item in items"
      :key="item.id"
      class="grid gap-1.5 py-3 first:pt-0 last:pb-0"
      :data-item="item.id"
    >
      <h4 class="min-w-0 truncate text-sm font-semibold text-primary">
        <RouterLink
          v-if="link(item)"
          :to="link(item) ?? ''"
          class="hover:underline"
        >
          {{ item.target_label ?? item.target_id }}
        </RouterLink>
        <span v-else>{{ item.target_label ?? item.target_id }}</span>
      </h4>
      <AiChangesetItem
        :item="item"
        :busy="busy"
        :locales="locales(item)"
        :scope="scope"
        @accept="(overwrite) => emit('accept', item, overwrite)"
        @reject="emit('reject', item)"
      />
    </li>
  </ul>
</template>
