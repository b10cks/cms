<script setup lang="ts">
import { ContextMenuItem, type ContextMenuItemProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<
  ContextMenuItemProps & { class?: HTMLAttributes['class']; inset?: boolean }
>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <ContextMenuItem
    v-bind="forwardedProps"
    :class="
      cn(
        'icon-anim menu-row',
        inset && 'pl-8',
        props.class
      )
    "
  >
    <slot />
  </ContextMenuItem>
</template>
