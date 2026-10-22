<script setup lang="ts">
/**
 * Shares of a whole: one stacked bar over a list of rows, largest first. Pointing at a segment
 * or a row fades every other share, in the bar and in the list. Items keep their own color (a
 * block's color) or take the series tokens in fixed order; anything past `limit` folds into one
 * "Other" row.
 */
export interface DistributionItem {
  name: string
  count: number
  color?: string | null
}

const props = withDefaults(
  defineProps<{
    items: DistributionItem[]
    limit?: number
    format?: (value: number) => string
  }>(),
  {
    limit: 6,
    format: (value: number) => String(value),
  }
)

const { t } = useI18n()
const { formatNumber } = useFormat()

const SERIES = ['var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)']

const rows = computed(() => {
  const sorted = props.items.filter((item) => item.count > 0).toSorted((a, b) => b.count - a.count)
  const total = sorted.reduce((sum, item) => sum + item.count, 0)
  const shown = sorted.length > props.limit ? sorted.slice(0, props.limit - 1) : sorted
  const rest = sorted.slice(shown.length)
  const list = shown.map((item, index) => ({
    key: item.name,
    name: item.name,
    count: item.count,
    color: item.color || SERIES[index % SERIES.length],
  }))
  if (rest.length > 0) {
    list.push({
      key: '__other',
      name: t('dashboard.charts.other'),
      count: rest.reduce((sum, item) => sum + item.count, 0),
      color: 'var(--series-other)',
    })
  }
  return list.map((row) => ({ ...row, share: total > 0 ? (row.count / total) * 100 : 0 }))
})

const active = ref<string | null>(null)
</script>

<template>
  <div class="grid gap-4">
    <div
      class="flex h-2.5 gap-0.5"
      aria-hidden="true"
    >
      <span
        v-for="row in rows"
        :key="row.key"
        class="min-w-1 rounded-[3px] transition-opacity duration-100"
        :class="active && active !== row.key && 'opacity-30'"
        :style="{ flexGrow: row.count, background: row.color }"
        @pointerenter="active = row.key"
        @pointerleave="active = null"
      />
    </div>
    <ul class="grid">
      <li
        v-for="row in rows"
        :key="row.key"
        class="flex h-9 items-center gap-2.5 transition-opacity duration-100"
        :class="active && active !== row.key && 'opacity-30'"
        @pointerenter="active = row.key"
        @pointerleave="active = null"
      >
        <span
          class="size-2.5 shrink-0 rounded-[3px]"
          :style="{ background: row.color }"
          aria-hidden="true"
        />
        <span class="min-w-0 flex-1 truncate">{{ row.name }}</span>
        <span class="font-semibold text-primary tabular-nums">{{ format(row.count) }}</span>
        <span class="w-14 text-right text-sm text-muted tabular-nums">
          {{ formatNumber(row.share, row.share < 10 ? 1 : 0) }}%
        </span>
      </li>
    </ul>
  </div>
</template>
