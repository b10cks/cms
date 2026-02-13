<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { cn } from '@/lib/utils'
import {
  SplitterResizeHandle,
  type SplitterResizeHandleEmits,
  type SplitterResizeHandleProps,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<
  SplitterResizeHandleProps & {
    class?: HTMLAttributes['class']
    withHandle?: boolean
    visible?: boolean
  }
>()
const emits = defineEmits<SplitterResizeHandleEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props
  return delegated
})

const bgClass = computed(() => {
  return props.visible ? 'bg-border' : 'bg-transparent delay-100'
})

const handleClass = computed(() => {
  return props.visible
    ? 'opacity-100 bg-border text-primary group-hover:bg-accent group-hover:text-accent-foreground'
    : 'opacity-0 bg-accent text-accent-foreground delay-100'
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SplitterResizeHandle
    v-bind="forwarded"
    :class="
      cn(
        'group relative flex w-0.5 items-center justify-center transition after:absolute after:inset-y-0 after:left-1/2 after:w-1 after:-translate-x-1/2 hover:bg-accent focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none [&[data-orientation=vertical]]:h-px [&[data-orientation=vertical]]:w-full [&[data-orientation=vertical]]:after:left-0 [&[data-orientation=vertical]]:after:h-1 [&[data-orientation=vertical]]:after:w-full [&[data-orientation=vertical]]:after:translate-x-0 [&[data-orientation=vertical]]:after:-translate-y-1/2 [&[data-orientation=vertical]>div]:rotate-90',
        props.class,
        bgClass
      )
    "
  >
    <template v-if="props.withHandle">
      <div
        :class="
          cn(
            'z-10 flex h-4 w-3 items-center justify-center rounded-sm text-primary transition-opacity group-hover:opacity-100',
            handleClass
          )
        "
      >
        <Icon
          name="lucide:grip-vertical"
          size="0.75rem"
        />
      </div>
    </template>
  </SplitterResizeHandle>
</template>
