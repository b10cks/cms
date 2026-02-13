<script lang="ts" setup>
import { cn } from '@/lib/utils'
import {
  ScrollAreaCorner,
  ScrollAreaRoot,
  type ScrollAreaRootProps,
  ScrollAreaViewport,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'
import ScrollBar from './ScrollBar.vue'

const props = defineProps<ScrollAreaRootProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _a, type: _b, scrollHideDelay: _c, ...delegated } = props

  return delegated
})
</script>

<template>
  <ScrollAreaRoot
    :class="cn('relative overflow-hidden', props.class)"
    :scroll-hide-delay="1500"
    type="glimpse"
    v-bind="delegatedProps"
  >
    <ScrollAreaViewport
      :as-child="true"
      class="h-full w-full rounded-[inherit]"
    >
      <slot />
    </ScrollAreaViewport>
    <ScrollBar
      force-mount
      orientation="horizontal"
    />
    <ScrollBar
      force-mount
      orientation="vertical"
    />
    <ScrollAreaCorner />
  </ScrollAreaRoot>
</template>
