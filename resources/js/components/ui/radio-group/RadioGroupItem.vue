<script setup lang="ts">
import Icon from '~/components/Icon.vue'

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
        'peer aspect-square h-4 w-4 cursor-pointer rounded-full bg-input shadow focus:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
        props.class
      )
    "
  >
    <RadioGroupIndicator class="flex items-center justify-center">
      <Icon name="lucide:check" />
    </RadioGroupIndicator>
  </RadioGroupItem>
</template>
