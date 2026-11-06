<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <div
    :class="
      cn(
        'sticky bottom-0 z-10 flex flex-col-reverse gap-2 bg-background pt-2 sm:flex-row sm:justify-end',
        props.class
      )
    "
  >
    <slot />
  </div>
</template>
