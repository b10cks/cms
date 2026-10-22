<script setup lang="ts">
/**
 * A small line under a figure: one value per period, oldest first, gaps where a period has none.
 * Pointing at it reports the period through `hover`, so the tile can say the value in words.
 * `fromZero` keeps zero at the bottom, for counts; otherwise the line spans its own range.
 * Ported from V1Per.
 */
const props = defineProps<{
  values: Array<number | null>
  color: string
  label: string
  fromZero?: boolean
}>()
const emit = defineEmits<{ hover: [index: number | null] }>()

const W = 120
const H = 32
const PAD = 2
const id = useId()
const active = ref<number | null>(null)

type Point = { x: number; y: number }

const geometry = computed(() => {
  const present = props.values.filter((value): value is number => value !== null)
  if (present.length === 0) return null
  const min = props.fromZero ? 0 : Math.min(...present)
  const max = Math.max(...present)
  const span = max - min || 1
  const step = (W - PAD * 2) / Math.max(props.values.length - 1, 1)
  const points = props.values.map((value, index): Point | null =>
    value === null
      ? null
      : {
          x: PAD + index * step,
          y:
            max === min
              ? props.fromZero
                ? H - PAD
                : H / 2
              : PAD + (1 - (value - min) / span) * (H - PAD * 2),
        }
  )
  const runs: Point[][] = []
  let run: Point[] = []
  for (const point of points) {
    if (point) run.push(point)
    else if (run.length > 0) {
      runs.push(run)
      run = []
    }
  }
  if (run.length > 0) runs.push(run)
  const path = (entry: Point[]) =>
    `M${entry.map((point) => `${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join('L')}`
  const line = runs.map(path).join('')
  const area = runs
    .flatMap((entry) => {
      const first = entry[0]
      const last = entry.at(-1)
      return first && last && entry.length > 1
        ? [`${path(entry)}L${last.x.toFixed(1)} ${H}L${first.x.toFixed(1)} ${H}Z`]
        : []
    })
    .join('')
  return { line, area, step }
})

function point(event: PointerEvent) {
  const box = (event.currentTarget as SVGElement).getBoundingClientRect()
  const x = ((event.clientX - box.left) / box.width) * W
  const index = Math.round((x - PAD) / (geometry.value?.step ?? 1))
  active.value = Math.max(0, Math.min(props.values.length - 1, index))
  emit('hover', active.value)
}

function leave() {
  active.value = null
  emit('hover', null)
}
</script>

<template>
  <svg
    v-if="geometry"
    :viewBox="`0 0 ${W} ${H}`"
    preserveAspectRatio="none"
    role="img"
    :aria-label="label"
    class="block h-8 w-full touch-none overflow-visible"
    @pointermove="point"
    @pointerleave="leave"
  >
    <defs>
      <linearGradient
        :id="`${id}-fill`"
        x1="0"
        x2="0"
        y1="0"
        y2="1"
      >
        <stop
          offset="0"
          :style="{ stopColor: color, stopOpacity: 0.28 }"
        />
        <stop
          offset="1"
          :style="{ stopColor: color, stopOpacity: 0 }"
        />
      </linearGradient>
    </defs>
    <path
      :d="geometry.area"
      :fill="`url(#${id}-fill)`"
    />
    <path
      :d="geometry.line"
      fill="none"
      :stroke="color"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />
    <line
      v-if="active !== null"
      :x1="PAD + active * geometry.step"
      :x2="PAD + active * geometry.step"
      y1="0"
      :y2="H"
      class="stroke-border-strong"
      stroke-width="1"
      vector-effect="non-scaling-stroke"
    />
  </svg>
  <div
    v-else
    class="h-8"
    aria-hidden="true"
  />
</template>
