<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffRichText, type DiffBlock } from '~/utils/richtext-diff'

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
      <span
        v-if="block.formattingOnly"
        class="bg-info/15 text-info shrink-0 rounded px-1 py-0.5 text-[10px] leading-none"
      >
        formatting
      </span>
      <span class="min-w-0">
        <DiffSegments :segments="block.segments" />
      </span>
    </div>
  </div>
</template>
