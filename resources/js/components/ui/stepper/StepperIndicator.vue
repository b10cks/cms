<script lang="ts" setup>
import type { StepperIndicatorProps } from 'reka-ui'
import { StepperIndicator, useForwardProps } from 'reka-ui'
import { cn } from '@/lib/utils'

import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<StepperIndicatorProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <StepperIndicator
    v-bind="forwarded"
    :class="
      cn(
        'inline-flex h-8 w-8 items-center justify-center rounded-full text-muted',
        // Disabled
        'group-data-[disabled]:text-muted group-data-[disabled]:opacity-50',
        // Active
        'group-data-[state=active]:bg-primary group-data-[state=active]:text-primary-foreground',
        // Completed
        'group-data-[state=completed]:bg-success-background group-data-[state=completed]:text-success-foreground',
        props.class
      )
    "
  >
    <slot />
  </StepperIndicator>
</template>
