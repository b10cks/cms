<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { TagsInputInputProps } from 'reka-ui'
import { TagsInputInput, useForwardProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

const props = defineProps<TagsInputInputProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputInput
    v-bind="forwardedProps"
    :class="cn('min-h-5 flex-1 bg-transparent px-1 text-sm focus:outline-none', props.class)"
  />
</template>
