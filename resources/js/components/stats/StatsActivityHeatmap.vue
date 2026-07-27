<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'

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

const props = defineProps<{
  title: string
  activity: ActivityCalendar
}>()

const { t } = useI18n()
const { formatNumber, formatDateTime } = useFormat()
const route = useRoute()

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

const monthLabels = computed(() =>
  weeks.value.map((week, index) => {
    const first = week.find((cell) => cell.date)
    if (!first?.date) return null

    const previous = weeks.value[index - 1]?.find((cell) => cell.date)
    if (previous?.date && monthOf(previous.date) === monthOf(first.date)) return null

    return formatDateTime(first.date, 'MMM')
  })
)

// 2024-01-01 is a Monday; the grid rows run Monday..Sunday.
const weekdayLabels = Array.from({ length: 7 }, (_, index) =>
  index % 2 === 0 ? formatDateTime(new Date(2024, 0, 1 + index), 'ddd') : ''
)

const hovered = ref<{ cell: Cell; x: number; y: number } | null>(null)

const showTooltip = (cell: Cell, event: MouseEvent) => {
  if (!cell.date) return
  const target = event.currentTarget as HTMLElement
  hovered.value = { cell, x: target.offsetLeft + target.offsetWidth / 2, y: target.offsetTop }
}

</script>

<template>
  <Card>
    <CardHeader class="flex-row items-start justify-between gap-4 pb-1">
      <div>
        <CardTitle>{{ title }}</CardTitle>
        <p class="mt-1 text-sm text-muted">
          {{
            t('dashboard.activity.summary', {
              count: formatNumber(activity.total),
              days: formatNumber(activity.active_days),
            })
          }}
        </p>
      </div>
      <RouterLink
        class="shrink-0 text-sm text-accent hover:underline"
        :to="{ name: 'space-audit-logs', params: { space: route.params.space } }"
      >
        {{ t('dashboard.activity.view_log') }}
      </RouterLink>
    </CardHeader>

    <CardContent>
      <!-- pt-7 keeps the hover tooltip of the top row inside the scroll container -->
      <div class="overflow-x-auto pt-7 pb-1">
        <div class="relative inline-block min-w-max">
          <div class="flex gap-[3px] pl-9">
            <div
              v-for="(label, index) in monthLabels"
              :key="`month-${index}`"
              class="relative h-4 w-[11px]"
            >
              <span
                v-if="label"
                class="absolute top-0 left-0 text-[11px] whitespace-nowrap text-muted"
              >
                {{ label }}
              </span>
            </div>
          </div>

          <div class="flex gap-[3px]">
            <div class="flex w-9 flex-col gap-[3px] pr-2">
              <span
                v-for="(label, index) in weekdayLabels"
                :key="`weekday-${index}`"
                class="h-[11px] text-right text-[11px] leading-[11px] text-muted"
              >
                {{ label }}
              </span>
            </div>

            <div
              v-for="(week, weekIndex) in weeks"
              :key="`week-${weekIndex}`"
              class="flex flex-col gap-[3px]"
            >
              <div
                v-for="(cell, dayIndex) in week"
                :key="`day-${weekIndex}-${dayIndex}`"
                class="size-[11px] rounded-[2px]"
                :class="cell.date ? 'ring-1 ring-border/60' : 'opacity-0'"
                :style="{ background: LEVEL_BACKGROUNDS[cell.level] }"
                @mouseenter="showTooltip(cell, $event)"
                @mouseleave="hovered = null"
              />
            </div>
          </div>

          <div
            v-if="hovered"
            class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md border border-popover-border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-md"
            :style="{ left: `${hovered.x}px`, top: `${hovered.y - 4}px` }"
          >
            <span class="font-medium text-primary">
              {{ t('dashboard.activity.events', { count: formatNumber(hovered.cell.count) }) }}
            </span>
            <span class="text-muted"> · {{ formatDateTime(hovered.cell.date!, 'LL') }}</span>
          </div>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
          <span>
            {{ t('dashboard.activity.current_streak') }}:
            <span class="text-primary">{{ formatNumber(activity.current_streak) }}</span>
          </span>
          <span>
            {{ t('dashboard.activity.longest_streak') }}:
            <span class="text-primary">{{ formatNumber(activity.longest_streak) }}</span>
          </span>
          <span
            v-for="contributor in activity.top_contributors.slice(0, 3)"
            :key="contributor.name"
          >
            {{ contributor.name }}:
            <span class="text-primary">{{ formatNumber(contributor.count) }}</span>
          </span>
        </div>

        <div class="flex items-center gap-1 text-[11px] text-muted">
          <span class="mr-1">{{ t('dashboard.activity.less') }}</span>
          <span
            v-for="level in [0, 1, 2, 3, 4]"
            :key="`legend-${level}`"
            class="size-[11px] rounded-[2px] ring-1 ring-border/60"
            :style="{ background: LEVEL_BACKGROUNDS[level] }"
          />
          <span class="ml-1">{{ t('dashboard.activity.more') }}</span>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
