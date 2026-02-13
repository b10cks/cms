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
        'border-b border-b-input transition-colors hover:bg-muted/10 data-[state=selected]:bg-muted/20',
        props.class
      )
    "
  >
    <slot />
  </tr>
</template>
