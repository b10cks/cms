<script setup lang="ts">
import { divergingScale, scalePosition } from '~/lib/chart-scale'

/**
 * Stacked bars over periods: positive values stack up from the zero line, negative ones down
 * (growth against churn). Same frame as `TrendChart`: legend and the pointed period's readout
 * above the plot, 13px axes, one tab stop with arrow keys.
 */
export interface BarSeries {
  key: string
  label: string
  /** A CSS color, normally one of the `--series-*` tokens. */
  color: string
  values: number[]
}

const props = withDefaults(
  defineProps<{
    /** Display label per period, oldest first. */
    periods: string[]
    series: BarSeries[]
    format?: (value: number) => string
    /** Plot height in pixels. */
    height?: number
  }>(),
  {
    format: (value: number) => String(value),
    height: 224,
  }
)

const { t } = useI18n()

const count = computed(() => props.periods.length)

const scale = computed(() => {
  let high = 0
  let low = 0
  for (let index = 0; index < count.value; index++) {
    const values = props.series.map((entry) => entry.values[index] ?? 0)
    high = Math.max(
      high,
      values.filter((value) => value > 0).reduce((a, b) => a + b, 0)
    )
    low = Math.min(
      low,
      values.filter((value) => value < 0).reduce((a, b) => a + b, 0)
    )
  }
  return divergingScale(high, low)
})

const level = (value: number) => scalePosition(scale.value, value)

const columns = computed(() =>
  props.periods.map((period, index) => {
    let up = 0
    let down = 0
    const segments = props.series.flatMap((entry) => {
      const value = entry.values[index] ?? 0
      if (value === 0) return []
      const from = value > 0 ? up : down + value
      const to = value > 0 ? up + value : down
      if (value > 0) up += value
      else down += value
      return [
        {
          key: entry.key,
          color: entry.color,
          bottom: level(from),
          height: level(to) - level(from),
        },
      ]
    })
    return { period, index, segments }
  })
)

/** At most six period labels, centered under their bars. */
const labelEvery = computed(() => Math.max(1, Math.ceil(count.value / 6)))

const plot = ref<HTMLElement | null>(null)
const hovered = ref<number | null>(null)

const hover = (event: PointerEvent) => {
  const box = plot.value?.getBoundingClientRect()
  if (!box || count.value === 0) return
  const index = Math.floor(((event.clientX - box.left) / box.width) * count.value)
  hovered.value = Math.max(0, Math.min(count.value - 1, index))
}

const step = (delta: number) => {
  if (count.value === 0) return
  const from = hovered.value ?? (delta > 0 ? -1 : count.value)
  hovered.value = Math.max(0, Math.min(count.value - 1, from + delta))
}

const readout = computed(() => {
  if (hovered.value === null) return null
  const index = hovered.value
  return {
    period: props.periods[index],
    values: props.series.map((entry) => ({
      key: entry.key,
      color: entry.color,
      value: props.format(entry.values[index] ?? 0),
    })),
  }
})
</script>

<template>
  <figure class="m-0 select-none">
    <figcaption class="sr-only">{{ series.map((entry) => entry.label).join(', ') }}</figcaption>
    <div
      class="mb-3 flex h-5 items-center gap-x-4 overflow-hidden text-sm whitespace-nowrap text-muted tabular-nums"
      aria-hidden="true"
    >
      <span
        v-for="entry in series"
        :key="entry.key"
        class="flex items-center gap-1.5"
      >
        <span
          class="size-2.5 rounded-[3px]"
          :style="{ background: entry.color }"
        />
        {{ entry.label }}
      </span>
      <span
        v-if="readout"
        class="ml-auto flex min-w-0 items-center gap-3 truncate text-primary"
      >
        <span class="text-muted">{{ readout.period }}</span>
        <span
          v-for="entry in readout.values"
          :key="entry.key"
          class="flex items-center gap-1.5"
        >
          <span
            class="size-2 rounded-full"
            :style="{ background: entry.color }"
          />
          {{ entry.value }}
        </span>
      </span>
    </div>
    <div
      class="grid grid-cols-[auto_1fr] gap-x-3"
      aria-hidden="true"
    >
      <div
        class="relative min-w-8 text-right text-sm text-muted tabular-nums"
        :style="{ height: `${height}px` }"
      >
        <span class="invisible block h-0 overflow-hidden whitespace-nowrap">
          {{
            scale.ticks.map((tick) => format(tick)).reduce((a, b) => (b.length > a.length ? b : a))
          }}
        </span>
        <span
          v-for="tick in scale.ticks"
          :key="tick"
          class="absolute right-0 block translate-y-1/2 leading-none whitespace-nowrap"
          :style="{ bottom: `${level(tick)}%` }"
        >
          {{ format(tick) }}
        </span>
      </div>
      <div
        ref="plot"
        tabindex="0"
        class="relative touch-pan-y rounded-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
        :style="{ height: `${height}px` }"
        @pointermove="hover"
        @pointerleave="hovered = null"
        @blur="hovered = null"
        @keydown.right.prevent="step(1)"
        @keydown.left.prevent="step(-1)"
        @keydown.home.prevent="hovered = 0"
        @keydown.end.prevent="hovered = count - 1"
      >
        <span
          v-for="tick in scale.ticks"
          :key="tick"
          class="absolute inset-x-0 border-t"
          :class="tick === 0 ? 'border-border-strong' : 'border-series-grid'"
          :style="{ bottom: `${level(tick)}%` }"
        />
        <div
          v-for="column in columns"
          :key="column.index"
          class="absolute inset-y-0 rounded-sm transition-colors duration-100"
          :class="hovered === column.index && 'bg-series-grid/60'"
          :style="{ left: `${(column.index / count) * 100}%`, width: `${100 / count}%` }"
        >
          <span
            v-for="segment in column.segments"
            :key="segment.key"
            class="absolute inset-x-[22%] rounded-[3px] ring-1 ring-card"
            :style="{
              bottom: `${segment.bottom}%`,
              height: `${segment.height}%`,
              background: segment.color,
            }"
          />
        </div>
      </div>
      <span />
      <div class="relative mt-2 h-5 text-sm text-muted tabular-nums">
        <template
          v-for="column in columns"
          :key="column.index"
        >
          <span
            v-if="column.index % labelEvery === 0"
            class="absolute -translate-x-1/2 whitespace-nowrap"
            :style="{ left: `${((column.index + 0.5) / count) * 100}%` }"
          >
            {{ column.period }}
          </span>
        </template>
      </div>
    </div>
    <div class="sr-only">
      <table>
        <caption>
          {{
            series.map((entry) => entry.label).join(', ')
          }}
        </caption>
        <thead>
          <tr>
            <th scope="col">{{ t('dashboard.charts.period') }}</th>
            <th
              v-for="entry in series"
              :key="entry.key"
              scope="col"
            >
              {{ entry.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(period, index) in periods"
            :key="index"
          >
            <th scope="row">{{ period }}</th>
            <td
              v-for="entry in series"
              :key="entry.key"
            >
              {{ format(entry.values[index] ?? 0) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </figure>
</template>
