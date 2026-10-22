<script setup lang="ts">
import { useElementSize } from '@vueuse/core'

export interface ActivityCalendar {
  start: string
  end: string
  days: Record<string, number>
  total: number
  max: number
  active_days: number
  current_streak: number
  longest_streak: number
  top_contributors: { name: string; count: number }[]
}

/**
 * The year of audit events as a calendar: one column per week, one row per weekday. The cell
 * size is a whole pixel count derived from the panel width, so every gap is exactly 3px; the
 * grid scrolls sideways only when a cell would drop below 10px. Pointing at a day shows its
 * count in a tooltip above the cell; the line under the grid holds the streaks and the most
 * active people.
 */
const props = defineProps<{
  activity: ActivityCalendar
}>()

const { t } = useI18n()
const { formatNumber, formatDateTime } = useFormat()

interface Cell {
  date: string | null
  count: number
  level: number
}

// Sequential single-hue ramp mixed into the card surface, so it steps
// light -> dark on light themes and dark -> bright on dark themes.
const LEVEL_BACKGROUNDS = [
  'var(--muted-background)',
  'color-mix(in oklab, var(--accent) 25%, var(--card))',
  'color-mix(in oklab, var(--accent) 45%, var(--card))',
  'color-mix(in oklab, var(--accent) 70%, var(--card))',
  'var(--accent)',
]

const levelOf = (count: number): number => {
  if (count <= 0) return 0
  const max = Math.max(props.activity.max, 1)
  return Math.min(4, Math.max(1, Math.ceil((count / max) * 4)))
}

// The backend emits a contiguous, Monday-aligned day map, so chunking by 7
// yields calendar weeks as columns.
const weeks = computed<Cell[][]>(() => {
  const cells: Cell[] = Object.entries(props.activity.days ?? {}).map(([date, count]) => ({
    date,
    count,
    level: levelOf(count),
  }))

  const result: Cell[][] = []
  for (let i = 0; i < cells.length; i += 7) {
    const week = cells.slice(i, i + 7)
    while (week.length < 7) {
      week.push({ date: null, count: 0, level: 0 })
    }
    result.push(week)
  }
  return result
})

const monthOf = (date: string): number => Number(date.slice(5, 7))
const firstDate = (week: Cell[] | undefined) => week?.find((cell) => cell.date)?.date

const monthLabels = computed(() =>
  weeks.value.map((week, index) => {
    const first = firstDate(week)
    if (!first) return null

    const previous = firstDate(weeks.value[index - 1])
    if (previous && monthOf(previous) === monthOf(first)) return null

    // A partial first month gets no label when the next month starts within three weeks,
    // so the two names never overlap.
    if (index === 0) {
      const nextMonthAt = weeks.value.findIndex((entry) => {
        const date = firstDate(entry)
        return date && monthOf(date) !== monthOf(first)
      })
      if (nextMonthAt !== -1 && nextMonthAt < 3) return null
    }

    return formatDateTime(first, 'MMM')
  })
)

// 2024-01-01 is a Monday; the grid rows run Monday..Sunday. Calendar-day strings,
// so the display time zone cannot shift a label onto the neighbouring weekday.
const weekdayLabels = Array.from({ length: 7 }, (_, index) =>
  index % 2 === 0 ? formatDateTime(`2024-01-0${1 + index}`, 'ddd') : ''
)

const root = useTemplateRef<HTMLElement>('root')
const hovered = ref<{ cell: Cell; x: number; y: number } | null>(null)

/** Anchors the tooltip to the cell, relative to the component so the scroller cannot clip it. */
const showTooltip = (cell: Cell, event: PointerEvent) => {
  const box = root.value?.getBoundingClientRect()
  if (!cell.date || !box) {
    hovered.value = null
    return
  }
  const target = (event.currentTarget as HTMLElement).getBoundingClientRect()
  hovered.value = { cell, x: target.left - box.left + target.width / 2, y: target.top - box.top }
}

/** The footer line: both streaks, then the three most active people. */
const facts = computed(() => [
  {
    label: t('dashboard.activity.current_streak'),
    value: t('dashboard.activity.days', { count: formatNumber(props.activity.current_streak) }, props.activity.current_streak),
  },
  {
    label: t('dashboard.activity.longest_streak'),
    value: t('dashboard.activity.days', { count: formatNumber(props.activity.longest_streak) }, props.activity.longest_streak),
  },
  ...props.activity.top_contributors.slice(0, 3).map((contributor) => ({
    label: contributor.name,
    value: formatNumber(contributor.count),
  })),
])

const LABEL_WIDTH = 40
const GAP = 3
const scroller = useTemplateRef<HTMLElement>('scroller')
const { width } = useElementSize(scroller)
const cellSize = computed(() => {
  const columns = weeks.value.length || 1
  return Math.max(10, Math.floor((width.value - LABEL_WIDTH - GAP * columns) / columns))
})
</script>

<template>
  <div
    ref="root"
    class="relative select-none"
  >
    <div
      ref="scroller"
      class="overflow-x-auto"
    >
      <div
        class="grid w-max"
        :style="{
          gap: `${GAP}px`,
          gridTemplateColumns: `${LABEL_WIDTH}px repeat(${weeks.length}, ${cellSize}px)`,
          gridTemplateRows: `1.5rem repeat(7, ${cellSize}px)`,
        }"
        @pointerleave="hovered = null"
      >
        <span />
        <span
          v-for="(label, index) in monthLabels"
          :key="`month-${index}`"
          class="relative"
        >
          <span
            v-if="label"
            class="absolute top-0 left-0 text-sm whitespace-nowrap text-muted"
          >
            {{ label }}
          </span>
        </span>

        <template
          v-for="day in 7"
          :key="`row-${day}`"
        >
          <span class="self-center pr-2 text-right text-sm leading-none text-muted">
            {{ weekdayLabels[day - 1] }}
          </span>
          <span
            v-for="(week, weekIndex) in weeks"
            :key="`day-${weekIndex}-${day}`"
            class="rounded-[3px] transition-shadow duration-100"
            :class="[
              week[day - 1].date ? 'ring-1 ring-border/60 ring-inset' : 'opacity-0',
              hovered?.cell === week[day - 1] && 'ring-2 ring-primary/70',
            ]"
            :style="{ background: LEVEL_BACKGROUNDS[week[day - 1].level] }"
            @pointerenter="showTooltip(week[day - 1], $event)"
          />
        </template>
      </div>
    </div>

    <div
      v-if="hovered"
      class="pointer-events-none absolute z-10 flex -translate-x-1/2 -translate-y-full gap-1.5 rounded-md border border-popover-border bg-popover px-2 py-1 text-sm whitespace-nowrap text-popover-foreground shadow-soft-lg"
      :style="{ left: `${hovered.x}px`, top: `${hovered.y - 6}px` }"
    >
      <span class="font-semibold text-primary tabular-nums">
        {{ t('dashboard.activity.events', { count: formatNumber(hovered.cell.count) }, hovered.cell.count) }}
      </span>
      <span class="text-muted">{{ formatDateTime(hovered.cell.date!, 'LL') }}</span>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-sm text-muted">
      <dl class="flex flex-wrap items-center gap-x-5 gap-y-1">
        <div
          v-for="fact in facts"
          :key="fact.label"
          class="flex gap-1.5"
        >
          <dt>{{ fact.label }}</dt>
          <dd class="font-semibold text-primary tabular-nums">{{ fact.value }}</dd>
        </div>
      </dl>

      <span
        class="flex shrink-0 items-center gap-1"
        aria-hidden="true"
      >
        <span class="mr-1">{{ t('dashboard.activity.less') }}</span>
        <span
          v-for="level in [0, 1, 2, 3, 4]"
          :key="`legend-${level}`"
          class="size-3 rounded-[3px] ring-1 ring-border/60 ring-inset"
          :style="{ background: LEVEL_BACKGROUNDS[level] }"
        />
        <span class="ml-1">{{ t('dashboard.activity.more') }}</span>
      </span>
    </div>
  </div>
</template>
