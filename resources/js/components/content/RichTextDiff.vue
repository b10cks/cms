<script setup lang="ts">
import { diffRichText, type DiffBlock, type DiffSegment } from '~/utils/richtext-diff'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const blocks = computed(() => diffRichText(props.oldValue, props.newValue))

const blockClasses = (block: DiffBlock): string => {
  const classes: string[] = []

  if (block.label?.startsWith('H')) {
    classes.push('font-semibold', block.label === 'H1' || block.label === 'H2' ? 'text-base' : 'text-sm')
  }

  switch (block.kind) {
    case 'added':
      classes.push('bg-success/10')
      break
    case 'removed':
      classes.push('bg-destructive/10')
      break
    case 'unchanged':
      classes.push('text-muted-foreground')
      break
  }

  return classes.join(' ')
}

const segmentClasses = (segment: DiffSegment): string => {
  switch (segment.type) {
    case 'added':
      return 'rounded-sm bg-success/25 text-success no-underline'
    case 'removed':
      return 'rounded-sm bg-destructive/25 text-destructive line-through decoration-destructive/60'
    default:
      return ''
  }
}
</script>

<template>
  <div class="space-y-1 text-sm">
    <div
      v-for="(block, index) in blocks"
      :key="index"
      class="flex items-baseline gap-2 rounded px-2 py-1 whitespace-pre-wrap"
      :class="blockClasses(block)"
    >
      <span
        v-if="block.label"
        class="bg-muted text-muted-foreground shrink-0 rounded px-1 py-0.5 font-mono text-[10px] leading-none"
      >
        {{ block.label }}
      </span>
      <span class="min-w-0">
        <template
          v-for="(segment, segmentIndex) in block.segments"
          :key="segmentIndex"
        >
          <ins
            v-if="segment.type === 'added'"
            :class="segmentClasses(segment)"
            >{{ segment.text }}</ins
          >
          <del
            v-else-if="segment.type === 'removed'"
            :class="segmentClasses(segment)"
            >{{ segment.text }}</del
          >
          <span v-else>{{ segment.text }}</span>
        </template>
      </span>
    </div>
  </div>
</template>
