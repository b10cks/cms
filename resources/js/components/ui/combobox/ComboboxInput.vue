<script setup lang="ts">
import {
  ComboboxInput,
  type ComboboxInputEmits,
  type ComboboxInputProps,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<
  ComboboxInputProps & {
    class?: HTMLAttributes['class']
  }
>()

const emits = defineEmits<ComboboxInputEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)

/**
 * Rendered `as-child` inside a `TagsInput`, the shell already draws the field
 * edge; only the standalone input carries it.
 */
const fieldClass =
  'flex h-9 w-full rounded-md border border-input-border bg-input px-3 py-1 text-sm text-primary shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50'
</script>

<template>
  <ComboboxInput
    v-bind="forwarded"
    :class="props.asChild ? props.class : cn(fieldClass, props.class)"
  >
    <slot />
  </ComboboxInput>
</template>
