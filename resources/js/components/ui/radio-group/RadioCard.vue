<script setup lang="ts">
import { RadioGroupItem, type RadioGroupItemProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

/**
 * One option of a `RadioCardGroup`. The whole card is the radio, so a click anywhere
 * selects it and the arrow keys move between cards. The `title` slot replaces the
 * title text when it needs more than a string.
 */
const props = defineProps<
  RadioGroupItemProps & {
    title?: string
    description?: string
    icon?: string
    class?: HTMLAttributes['class']
  }
>()

const delegatedProps = computed(() => {
  const { class: _, title: __, description: ___, icon: ____, ...delegated } = props

  return delegated
})

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <RadioGroupItem
    v-bind="forwarded"
    :class="
      cn(
        'group flex w-full min-w-0 cursor-pointer items-start gap-3 px-3 py-2.5 text-left transition-colors duration-100 outline-none focus-visible:outline-2 disabled:cursor-not-allowed disabled:opacity-50',
        'hover:bg-state-hover focus-visible:-outline-offset-2 focus-visible:outline-ring data-[state=checked]:bg-state-selected',
        props.class
      )
    "
  >
    <span
      class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border border-choice-border bg-input shadow-xs transition-colors duration-100 group-hover:border-choice-border-hover group-data-[state=checked]:border-accent-fill group-data-[state=checked]:bg-accent-fill"
      aria-hidden="true"
    >
      <span class="hidden size-1.5 rounded-full bg-white group-data-[state=checked]:block" />
    </span>
    <span class="grid min-w-0 flex-1 gap-0.5">
      <span class="flex min-w-0 items-center gap-2 leading-5 font-semibold text-primary">
        <Icon
          v-if="icon"
          :name="icon"
          class="shrink-0 text-muted"
        />
        <slot name="title">{{ title }}</slot>
      </span>
      <span
        v-if="description || $slots.default"
        class="text-sm text-muted"
      >
        <slot>{{ description }}</slot>
      </span>
    </span>
  </RadioGroupItem>
</template>
