<script lang="ts" setup>
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes['class']
    name?: string
    size?: string
  }>(),
  {
    name: 'lucide:chevron-right',
    size: '1rem',
  }
)
</script>

<template>
  <li
    role="presentation"
    aria-hidden="true"
    :class="cn('inline-flex [&>svg]:size-3.5', props.class)"
  >
    <slot>
      <Icon
        :name="name"
        :size="size"
      />
    </slot>
  </li>
</template>
