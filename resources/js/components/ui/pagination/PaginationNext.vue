<script setup lang="ts">
import { PaginationNext, type PaginationNextProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = withDefaults(
  defineProps<PaginationNextProps & { class?: HTMLAttributes['class'] }>(),
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
  <PaginationNext v-bind="delegatedProps">
    <Button :class="cn('h-9 w-9 !p-0', props.class)">
      <slot>
        <Icon name="lucide:chevron-right" />
      </slot>
    </Button>
  </PaginationNext>
</template>
