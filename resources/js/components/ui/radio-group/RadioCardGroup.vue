<script setup lang="ts">
import {
  RadioGroupRoot,
  type RadioGroupRootEmits,
  type RadioGroupRootProps,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

/**
 * A radio group whose options are `RadioCard`s: one framed list, rows split by hairlines,
 * each with a title and an optional description. `columns="2"` puts two options side by side from `sm` up.
 */
const props = withDefaults(
  defineProps<RadioGroupRootProps & { columns?: 1 | 2; class?: HTMLAttributes['class'] }>(),
  { columns: 1 }
)
const emits = defineEmits<RadioGroupRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, columns: __, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <RadioGroupRoot
    v-bind="forwarded"
    :class="
      cn(
        'grid',
        columns === 2 && 'sm:grid-cols-2',
        'divide-y divide-border overflow-hidden rounded-lg border border-border-strong bg-card shadow-xs',
        columns === 2 && 'sm:divide-x sm:divide-y-0',
        props.class
      )
    "
  >
    <slot />
  </RadioGroupRoot>
</template>
