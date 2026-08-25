<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <div
    :class="
      cn(
        'sticky bottom-0 z-10 flex flex-col-reverse bg-background sm:flex-row sm:justify-end sm:gap-x-2',
        props.class
      )
    "
  >
    <slot />
  </div>
</template>
