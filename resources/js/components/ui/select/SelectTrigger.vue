<script setup lang="ts">
import { SelectIcon, SelectTrigger, type SelectTriggerProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = defineProps<SelectTriggerProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <SelectTrigger
    v-bind="forwardedProps"
    :class="
      cn(
        'flex h-9 w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-input-border bg-input py-2 pr-2 pl-3 text-start text-sm font-medium whitespace-nowrap text-primary shadow-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-invalid:border-destructive aria-invalid:focus-visible:ring-destructive disabled:cursor-not-allowed disabled:opacity-50 data-[placeholder]:text-input-muted data-[state=open]:ring-2 data-[state=open]:ring-ring [&>span]:truncate',
        props.class
      )
    "
  >
    <slot />
    <SelectIcon as-child>
      <Icon
        name="lucide:chevron-down"
        size="1rem"
        class="shrink-0 text-muted"
      />
    </SelectIcon>
  </SelectTrigger>
</template>
