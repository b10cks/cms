<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { cn } from '@/lib/utils'
import { PaginationEllipsis, type PaginationEllipsisProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<PaginationEllipsisProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <PaginationEllipsis
    v-bind="delegatedProps"
    :class="cn('flex h-9 w-9 items-center justify-center', props.class)"
  >
    <slot>
      <Icon name="lucide:more-horizontal" />
    </slot>
  </PaginationEllipsis>
</template>
