<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { cn } from '@/lib/utils'
import { DropdownMenuSubTrigger, type DropdownMenuSubTriggerProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<DropdownMenuSubTriggerProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <DropdownMenuSubTrigger
    v-bind="forwardedProps"
    :class="
      cn(
        'flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm font-semibold outline-none select-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[state=open]:bg-accent data-[state=open]:text-accent-foreground',
        props.class
      )
    "
  >
    <slot />
    <Icon
      name="lucide:chevron-right"
      class="ml-auto"
    />
  </DropdownMenuSubTrigger>
</template>
