<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <tfoot
    :class="
      cn(
        'border-t border-t-border-strong bg-muted-background font-medium [&>tr]:last:border-b-0 [&>tr:hover]:bg-transparent',
        props.class
      )
    "
  >
    <slot />
  </tfoot>
</template>
