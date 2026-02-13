<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import TableHead from './TableHead.vue'

const sortBy = defineModel<{
  column: string
  direction: 'asc' | 'desc'
}>()

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
    class="cursor-pointer"
    @click="handleSort()"
  >
    <div :class="wrapClass">
      <div>
        <slot />
      </div>
      <Icon
        v-if="sortable && sortBy?.column === props.column"
        :name="sortBy.direction === 'asc' ? 'lucide:chevron-up' : 'lucide:chevron-down'"
      />
    </div>
  </TableHead>
</template>
