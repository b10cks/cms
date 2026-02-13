<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { cn } from '@/lib/utils'
import { ListboxFilter, type ListboxFilterProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'
import { useCommand } from '.'

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<
  ListboxFilterProps & {
    class?: HTMLAttributes['class']
  }
>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)

const { filterState } = useCommand()
</script>

<template>
  <div
    class="flex items-center gap-2 border-b border-border px-3"
    cmdk-input-wrapper
  >
    <Icon
      name="lucide:search"
      class="shrink-0 opacity-50"
    />
    <ListboxFilter
      v-bind="{ ...forwardedProps, ...$attrs }"
      v-model="filterState.search"
      auto-focus
      :class="
        cn(
          'flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-none placeholder:text-muted disabled:cursor-not-allowed disabled:opacity-50',
          props.class
        )
      "
    />
  </div>
</template>
