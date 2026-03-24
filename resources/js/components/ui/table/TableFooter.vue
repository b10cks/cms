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
      cn('border-t border-t-border bg-muted/20 font-medium [&>tr]:last:border-b-0', props.class)
    "
  >
    <slot />
  </tfoot>
</template>
