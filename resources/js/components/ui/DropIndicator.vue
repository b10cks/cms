<script setup lang="ts">
type Edge = 'top' | 'right' | 'bottom' | 'left'

const {
  edge,
  gap = '0px',
  inset = '0px',
  label,
} = defineProps<{
  edge: Edge
  gap?: string
  inset?: string
  label?: string
}>()

type Orientation = 'horizontal' | 'vertical'

const edgeToOrientationMap: Record<Edge, Orientation> = {
  top: 'horizontal',
  bottom: 'horizontal',
  left: 'vertical',
  right: 'vertical',
}

const orientationStyles: Record<Orientation, string> = {
  horizontal:
    'h-[--line-thickness] left-[--indicator-inset] right-[--indicator-inset] before:left-0 before:-translate-x-1/2',
  vertical:
    'w-[--line-thickness] top-[--indicator-inset] bottom-[--indicator-inset] before:top-0 before:-translate-y-1/2',
}

const edgeStyles: Record<Edge, string> = {
  top: 'top-[--line-offset] before:top-0',
  right: 'right-[--line-offset] before:right-0',
  bottom: 'bottom-[--line-offset] before:bottom-0',
  left: 'left-[--line-offset] before:left-0',
}

const labelStyles: Record<Edge, string> = {
  top: 'left-3 top-[calc(var(--line-offset)-1.5rem)]',
  right: 'right-[calc(var(--line-offset)+0.5rem)] top-2',
  bottom: 'left-3 bottom-[calc(var(--line-offset)-1.5rem)]',
  left: 'left-[calc(var(--line-offset)+0.5rem)] top-2',
}

const strokeSize = 3
const terminalSize = 10
const glowSize = 8
</script>

<template>
  <div
    aria-hidden="true"
    class="pointer-events-none absolute inset-0 z-20"
  >
    <div
      :class="[
        'absolute rounded-full bg-info shadow-[0_0_0_1px_hsl(var(--info)/0.15),0_0_0_var(--glow-size)_hsl(var(--info)/0.18)]',
        `before:content-[''] before:absolute before:h-[--terminal-size] before:w-[--terminal-size] before:rounded-full before:border-[length:--line-thickness] before:border-solid before:border-info before:bg-background before:shadow-[0_0_0_1px_hsl(var(--background)),0_0_0_6px_hsl(var(--info)/0.2)]`,
        orientationStyles[edgeToOrientationMap[edge]],
        edgeStyles[edge],
      ]"
      :style="{
        '--line-thickness': `${strokeSize}px`,
        '--line-offset': `calc(-0.5 * (${gap} + ${strokeSize}px))`,
        '--terminal-size': `${terminalSize}px`,
        '--glow-size': `${glowSize}px`,
        '--indicator-inset': inset,
      }"
    />
    <div
      v-if="label"
      :class="[
        'absolute rounded-md border border-info/30 bg-background/95 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-info shadow-sm backdrop-blur-sm',
        labelStyles[edge],
      ]"
    >
      {{ label }}
    </div>
  </div>
</template>
