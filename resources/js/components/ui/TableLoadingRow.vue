<script setup lang="ts">
import { Skeleton } from '~/components/ui/skeleton'
import { TableCell, TableRow } from '~/components/ui/table'

const props = withDefaults(
  defineProps<{
    colspan?: number
    rows?: number
    // kept for backwards compatibility with existing call sites
    icon?: string
    label?: string
  }>(),
  {
    colspan: 3,
    rows: 8,
    icon: undefined,
    label: undefined,
  }
)

const widths = ['w-2/3', 'w-1/2', 'w-3/5', 'w-3/4', 'w-2/5', 'w-1/2', 'w-3/5', 'w-2/3']

// full opacity for the top half, then fade out toward the bottom
const rowOpacity = (index: number) => {
  const half = props.rows / 2
  if (index <= half) return 1
  return Math.max(0.15, 1 - ((index - half) / half) * 0.85)
}
</script>

<template>
  <TableRow
    v-for="i in rows"
    :key="i"
    aria-hidden="true"
    class="pointer-events-none hover:bg-transparent"
    :style="{ opacity: rowOpacity(i) }"
  >
    <TableCell
      :colspan="colspan"
      class="py-3"
    >
      <Skeleton :class="['h-4', widths[(i - 1) % widths.length]]" />
    </TableCell>
  </TableRow>
</template>
