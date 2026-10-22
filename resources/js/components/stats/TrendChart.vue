<script setup lang="ts">
/**
 * A line chart over periods, one line per series on a shared zero-based scale. Pointing at it
 * (or arrowing through it, the chart is one tab stop) draws a crosshair and puts that period's
 * values in the readout row above the plot, so nothing floats over the lines. Every series gets
 * a fading area fill; a single series has no legend, its panel title names it. Adapted from
 * V1Per's LatencyChart.
 */
export interface TrendSeries {
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
    series: TrendSeries[]
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
const id = useId()

/**
 * The next round number at or above a positive value whose half is round too, so the middle
 * tick of a count never lands on a fraction. Counts start at 2, the smallest scale with a whole
 * middle.
 */
const roundUp = (value: number) => {
  const magnitude = 10 ** Math.floor(Math.log10(value))
  const steps = [2, 4, 6, 8, 10]
  return (steps.find((step) => step * magnitude >= value) ?? 10) * magnitude
}

const top = computed(() => {
  const highest = Math.max(0, ...props.series.flatMap((entry) => entry.values))
  return roundUp(Math.max(highest * 1.05, 2))
})

const count = computed(() => props.periods.length)
const left = (index: number) => (count.value > 1 ? (index / (count.value - 1)) * 100 : 50)
const level = (value: number) => (Math.min(Math.max(0, value), top.value) / top.value) * 100
const at = (index: number, value: number) =>
  `${left(index).toFixed(3)},${(100 - level(value)).toFixed(3)}`

const lines = computed(() =>
  props.series.map((entry) => {
    const line = entry.values.map((value, index) => `${index === 0 ? 'M' : 'L'}${at(index, value)}`)
    return {
      ...entry,
      line: line.join(' '),
      area:
        entry.values.length > 1
          ? `${line.join(' ')} L${left(entry.values.length - 1)},100 L0,100 Z`
          : '',
    }
  })
)

const ticks = computed(() => [top.value, top.value / 2, 0])
/** Overlapping fills stay readable when several series share the plot. */
const fillOpacity = computed(() => (props.series.length > 1 ? 0.12 : 0.22))

const axis = computed(() => {
  const last = count.value - 1
  if (last < 0) return []
  const marks = [{ index: 0, align: '' }]
  if (last > 1) marks.push({ index: Math.round(last / 2), align: '-translate-x-1/2' })
  if (last > 0) marks.push({ index: last, align: '-translate-x-full' })
  return marks.map((mark) => ({ ...mark, label: props.periods[mark.index] }))
})

const plot = ref<HTMLElement | null>(null)
const hovered = ref<number | null>(null)

const hover = (event: PointerEvent) => {
  const box = plot.value?.getBoundingClientRect()
  if (!box || count.value === 0) return
  const ratio = (event.clientX - box.left) / box.width
  hovered.value = Math.max(0, Math.min(count.value - 1, Math.round(ratio * (count.value - 1))))
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
      <template v-if="series.length > 1">
        <span
          v-for="entry in series"
          :key="entry.key"
          class="flex items-center gap-1.5"
        >
          <span
            class="h-0.5 w-3.5 rounded-full"
            :style="{ background: entry.color }"
          />
          {{ entry.label }}
        </span>
      </template>
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
            v-if="series.length > 1"
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
          {{ ticks.map((tick) => format(tick)).reduce((a, b) => (b.length > a.length ? b : a)) }}
        </span>
        <span
          v-for="tick in ticks"
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
          v-for="tick in ticks"
          :key="tick"
          class="absolute inset-x-0 border-t border-series-grid"
          :style="{ bottom: `${level(tick)}%` }"
        />
        <svg
          class="absolute inset-0 size-full overflow-visible"
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
        >
          <defs>
            <linearGradient
              v-for="entry in lines"
              :id="`${id}-${entry.key}`"
              :key="entry.key"
              x1="0"
              x2="0"
              y1="0"
              y2="1"
            >
              <stop
                offset="0"
                :style="{ stopColor: entry.color, stopOpacity: fillOpacity }"
              />
              <stop
                offset="1"
                :style="{ stopColor: entry.color, stopOpacity: 0 }"
              />
            </linearGradient>
          </defs>
          <path
            v-for="entry in lines"
            :key="`${entry.key}-area`"
            :d="entry.area"
            :fill="`url(#${id}-${entry.key})`"
          />
          <path
            v-for="entry in lines"
            :key="entry.key"
            :d="entry.line"
            fill="none"
            :stroke="entry.color"
            stroke-width="2"
            stroke-linejoin="round"
            stroke-linecap="round"
            vector-effect="non-scaling-stroke"
          />
        </svg>
        <template v-if="hovered !== null">
          <span
            class="absolute inset-y-0 border-l border-border-strong"
            :style="{ left: `${left(hovered)}%` }"
          />
          <span
            v-for="entry in series"
            :key="entry.key"
            class="absolute size-2.5 -translate-x-1/2 translate-y-1/2 rounded-full ring-2 ring-card"
            :style="{
              left: `${left(hovered)}%`,
              bottom: `${level(entry.values[hovered] ?? 0)}%`,
              background: entry.color,
            }"
          />
        </template>
      </div>
      <span />
      <div class="relative mt-2 h-5 text-sm text-muted tabular-nums">
        <span
          v-for="mark in axis"
          :key="mark.index"
          class="absolute whitespace-nowrap"
          :class="[mark.align, mark.align === '-translate-x-1/2' && 'max-sm:hidden']"
          :style="{ left: `${left(mark.index)}%` }"
        >
          {{ mark.label }}
        </span>
      </div>
    </div>
    <div class="sr-only">
      <table>
        <caption>{{ series.map((entry) => entry.label).join(', ') }}</caption>
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
