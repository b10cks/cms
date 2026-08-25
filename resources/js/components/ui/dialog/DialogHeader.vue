<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <div
    :class="
      cn(
        'sticky top-0 z-10 flex flex-col gap-y-1.5 bg-background text-center sm:text-left',
        props.class
      )
    "
  >
    <slot />
  </div>
</template>
