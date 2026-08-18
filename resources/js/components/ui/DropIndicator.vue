<script setup lang="ts">
import type { CSSProperties } from 'vue'

type Edge = 'top' | 'right' | 'bottom' | 'left'

const {
  edge,
  gap = '0px',
  inset = '0px',
  indent,
  label,
} = defineProps<{
  edge: Edge
  /** Distance between the host and its neighbour; the line is centred inside it. */
  gap?: string
  inset?: string
  /** Leading (left) offset for horizontal lines, used to reflect tree depth. Falls back to `inset`. */
  indent?: string
  label?: string
}>()

const STROKE = 3
const TERMINAL = 10

const isHorizontal = computed(() => edge === 'top' || edge === 'bottom')

// Half the gap plus half the stroke, pushed outside the host's edge, so the
// line sits exactly between the host and its neighbour.
const offset = computed(() => `calc(-0.5 * (${gap} + ${STROKE}px))`)

const lineStyle = computed<CSSProperties>(() =>
  isHorizontal.value
    ? { height: `${STROKE}px`, left: indent ?? inset, right: inset, [edge]: offset.value }
    : { width: `${STROKE}px`, top: inset, bottom: inset, [edge]: offset.value }
)

const terminalStyle = computed<CSSProperties>(() => ({
  width: `${TERMINAL}px`,
  height: `${TERMINAL}px`,
  borderWidth: `${STROKE}px`,
  ...(isHorizontal.value
    ? { left: 0, top: '50%', transform: 'translate(-50%, -50%)' }
    : { top: 0, left: '50%', transform: 'translate(-50%, -50%)' }),
}))

// The label hugs the far end of the line and is centred on it, so it never
// covers the host row's own content.
const labelStyle = computed<CSSProperties>(() => {
  const centre = `calc(${offset.value} + ${STROKE / 2}px)`

  switch (edge) {
    case 'top':
      return { right: '0.5rem', top: centre, transform: 'translateY(-50%)' }
    case 'bottom':
      return { right: '0.5rem', bottom: centre, transform: 'translateY(50%)' }
    case 'left':
      return { left: `calc(${centre} + 0.75rem)`, top: '0.5rem' }
    case 'right':
      return { right: `calc(${centre} + 0.75rem)`, top: '0.5rem' }
  }
})
</script>

<template>
  <div
    aria-hidden="true"
    class="pointer-events-none absolute inset-0 z-20"
  >
    <div
      class="absolute rounded-full bg-info ring-4 ring-info/20"
      :style="lineStyle"
    >
      <span
        class="absolute rounded-full border-solid border-info bg-background"
        :style="terminalStyle"
      />
    </div>
    <div
      v-if="label"
      class="absolute rounded-md border border-info/30 bg-background/95 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide whitespace-nowrap text-info shadow-sm backdrop-blur-sm"
      :style="labelStyle"
    >
      {{ label }}
    </div>
  </div>
</template>
