<script setup lang="ts">
import type { RadioGroupItemProps } from 'reka-ui'
import { RadioGroupIndicator, RadioGroupItem, useForwardProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<RadioGroupItemProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RadioGroupItem
    v-bind="forwardedProps"
    :class="
      cn(
        'peer aspect-square h-4 w-4 cursor-pointer rounded-full border border-choice-border bg-input shadow-xs transition-colors duration-100 hover:border-choice-border-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:border-choice-border disabled:opacity-50 disabled:shadow-none data-[state=checked]:border-accent-fill data-[state=checked]:bg-accent-fill',
        props.class
      )
    "
  >
    <RadioGroupIndicator class="flex h-full items-center justify-center">
      <span class="size-1.5 rounded-full bg-white" />
    </RadioGroupIndicator>
  </RadioGroupItem>
</template>
