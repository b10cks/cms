<script setup lang="ts">
import { PaginationPrev, type PaginationPrevProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = withDefaults(
  defineProps<PaginationPrevProps & { class?: HTMLAttributes['class'] }>(),
  {
    asChild: true,
  }
)

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <PaginationPrev v-bind="delegatedProps">
    <Button :class="cn('h-9 w-9 !p-0', props.class)">
      <slot>
        <Icon name="lucide:chevron-left" />
      </slot>
    </Button>
  </PaginationPrev>
</template>
