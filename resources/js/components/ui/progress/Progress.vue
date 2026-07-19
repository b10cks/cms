<script setup lang="ts">
import { ProgressIndicator, ProgressRoot, type ProgressRootProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<
    ProgressRootProps & {
      class?: HTMLAttributes['class']
      /** Bar color: default (primary), warning (near limit), destructive (over limit). */
      variant?: 'default' | 'warning' | 'destructive'
    }
  >(),
  {
    modelValue: 0,
    variant: 'default',
  }
)

const delegatedProps = computed(() => {
  const { class: _, variant: __, modelValue: ___, ...delegated } = props

  return delegated
})

// Percentages can exceed 100 (soft quotas) — the bar itself stays full. The
// clamped value is also what reaches reka-ui: its ProgressRoot rejects values
// beyond max (console error + reset to indeterminate).
const clamped = computed(() => Math.min(100, Math.max(0, props.modelValue ?? 0)))

const indicatorClass = computed(
  () =>
    ({
      default: 'bg-primary',
      warning: 'bg-warning',
      destructive: 'bg-destructive',
    })[props.variant]
)
</script>

<template>
  <ProgressRoot
    v-bind="delegatedProps"
    :model-value="clamped"
    :class="cn('relative h-2 w-full overflow-hidden rounded-full bg-secondary', props.class)"
  >
    <ProgressIndicator
      :class="cn('h-full w-full flex-1 transition-all', indicatorClass)"
      :style="`transform: translateX(-${100 - clamped}%);`"
    />
  </ProgressRoot>
</template>
