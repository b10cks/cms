<script setup lang="ts">
import ChangeIndicator from '~/components/stats/ChangeIndicator.vue'
import TrendSparkline from '~/components/stats/TrendSparkline.vue'
import { Skeleton } from '~/components/ui/skeleton'

/**
 * A KPI tile: a quiet label, the value as the loudest thing on the card with its change beside
 * it, one short detail, and optionally a sparkline over the selected periods. Pointing at the
 * sparkline puts the period's date in the label row and its figure in the value row: an absolute
 * series (traffic, requests) swaps the value, a `relative` series (what was added per period)
 * keeps the total and shows the period's change in the change slot. The tile never changes size.
 */
const props = defineProps<{
  label: string
  /** `null` while loading. */
  value: string | null
  detail?: string
  change?: { value: number; label: string; title?: string }
  /** One value per period; `null` where the period has none, drawn as a gap. */
  series?: Array<number | null>
  /** Display label per series point. */
  periods?: string[]
  format?: (value: number) => string
  /** The series holds changes per period (items created), not absolute values. */
  relative?: boolean
}>()

const hovered = ref<number | null>(null)

const readout = computed(() => {
  if (hovered.value === null || !props.series) return null
  const value = props.series[hovered.value] ?? null
  const format = (amount: number) => props.format?.(amount) ?? String(amount)
  return {
    period: props.periods?.[hovered.value] ?? '',
    value: props.relative ? null : value === null ? '–' : format(value),
    change: props.relative ? { value: value ?? 0, label: format(Math.abs(value ?? 0)) } : null,
  }
})
</script>

<template>
  <div
    class="flex min-w-0 flex-col gap-1 rounded-xl border border-border bg-card px-4 pt-3.5 pb-3 shadow-soft-sm"
  >
    <dt class="flex h-5 items-baseline justify-between gap-2 text-sm text-muted select-none">
      <span class="truncate">{{ label }}</span>
      <span
        v-if="readout"
        class="shrink-0 tabular-nums"
      >{{ readout.period }}</span>
    </dt>
    <dd class="flex h-9 min-w-0 items-baseline gap-2">
      <Skeleton
        v-if="value === null"
        class="my-1.5 h-7 w-24"
      />
      <template v-else>
        <span class="truncate text-figure font-semibold tracking-tight text-primary tabular-nums">
          {{ readout?.value ?? value }}
        </span>
        <ChangeIndicator
          v-if="readout?.change"
          v-bind="readout.change"
          show-zero
        />
        <ChangeIndicator
          v-else-if="change && !readout"
          v-bind="change"
        />
      </template>
    </dd>
    <dd class="h-5 truncate text-sm text-muted tabular-nums">
      {{ value === null ? '' : detail }}
    </dd>
    <dd
      v-if="series"
      class="mt-auto pt-1"
    >
      <TrendSparkline
        :values="series"
        color="var(--series-1)"
        :label="label"
        from-zero
        @hover="hovered = $event"
      />
    </dd>
  </div>
</template>
