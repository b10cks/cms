<script setup lang="ts">
import type { ComboboxAnchorProps } from 'reka-ui'
import { ComboboxAnchor, useForwardProps } from 'reka-ui'
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

const props = defineProps<ComboboxAnchorProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <ComboboxAnchor
    v-bind="forwarded"
    :class="cn(props.class)"
  >
    <slot />
  </ComboboxAnchor>
</template>
