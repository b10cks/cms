<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { TabsListProps } from 'reka-ui'
import { TabsList } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

const props = defineProps<TabsListProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <TabsList
    v-bind="delegatedProps"
    :class="
      cn('inline-flex items-center justify-center gap-1 rounded-lg bg-input p-1', props.class)
    "
  >
    <slot />
  </TabsList>
</template>
