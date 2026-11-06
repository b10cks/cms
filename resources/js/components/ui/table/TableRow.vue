<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <tr
    :class="
      cn(
        'h-11 border-b border-b-border transition-colors hover:bg-state-hover data-[state=selected]:bg-state-selected',
        props.class
      )
    "
  >
    <slot />
  </tr>
</template>
