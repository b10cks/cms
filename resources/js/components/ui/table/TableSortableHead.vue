<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import TableHead from './TableHead.vue'

const sortBy = defineModel<{
  column: string
  direction: 'asc' | 'desc'
}>({ required: true })

const props = withDefaults(
  defineProps<{
    column: string
    wrapClass?: string
    sortable?: boolean
  }>(),
  {
    wrapClass: 'flex items-center gap-1',
    sortable: true,
  }
)

const isSorted = computed(() => props.sortable && sortBy.value?.column === props.column)

const handleSort = () => {
  if (!props.sortable) return

  if (sortBy.value.column === props.column) {
    sortBy.value.direction = sortBy.value.direction === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = { column: props.column, direction: 'asc' }
  }
}
</script>

<template>
  <TableHead
    :class="
      sortable &&
      'group/sort cursor-pointer outline-none transition-colors duration-100 select-none hover:text-primary focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
    "
    :tabindex="sortable ? 0 : undefined"
    :aria-sort="isSorted ? (sortBy.direction === 'asc' ? 'ascending' : 'descending') : undefined"
    @click="handleSort()"
    @keydown.enter.prevent="handleSort()"
    @keydown.space.prevent="handleSort()"
  >
    <div :class="wrapClass">
      <div>
        <slot />
      </div>
      <Icon
        v-if="sortable"
        :name="isSorted && sortBy.direction === 'desc' ? 'lucide:chevron-down' : 'lucide:chevron-up'"
        :class="[
          'shrink-0 transition-opacity duration-100',
          isSorted ? 'text-primary' : 'text-muted opacity-0 group-hover/thead:opacity-100 group-focus-visible/sort:opacity-100',
        ]"
      />
    </div>
  </TableHead>
</template>
